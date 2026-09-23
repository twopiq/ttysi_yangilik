<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * config/site.php dagi moslikni sayt bazasining haqiqiy sxemasiga solishtiradi.
 *
 * Hech narsa yozmaydi. Maqsadi — noto'g'ri ustun nomi nashr paytida emas,
 * sozlash paytida ma'lum bo'lishi.
 */
class VerifySiteMapping extends Command
{
    protected $signature = 'site:verify';

    protected $description = 'Sayt bazasiga yozish sozlamasini tekshiradi (hech narsa yozmaydi)';

    private bool $failed = false;

    public function handle(): int
    {
        if (config('site.driver') !== 'database') {
            $this->components->warn("SITE_DRIVER='".config('site.driver')."'. Bu tekshiruv faqat 'database' uchun.");
        }

        $config = (array) config('site.database');

        try {
            DB::connection($config['connection'])->getPdo();
        } catch (Throwable $e) {
            $this->components->error('Sayt bazasiga ulanib bo\'lmadi: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Ulanish joyida.');

        $this->checkTable($config['connection'], $config['table'], $this->mainColumns($config));

        if ($config['layout'] === 'translations') {
            $settings = $config['translations'];
            $this->checkTable($config['connection'], $settings['table'], array_filter([
                $settings['foreign_key'],
                $settings['locale_column'],
                ...array_values($settings['columns']),
            ]));
        }

        if (filled($config['media']['table'] ?? null)) {
            $this->checkTable($config['connection'], $config['media']['table'], array_filter([
                $config['media']['foreign_key'],
                $config['media']['path_column'],
                $config['media']['position_column'] ?? null,
            ]));
        }

        $this->checkStorage($config);
        $this->checkRequiredColumnsAreCovered($config);

        $this->newLine();

        if ($this->failed) {
            $this->components->error('Sozlamada muammo bor — yuqoriga qarang.');

            return self::FAILURE;
        }

        $this->components->info('Sozlama sxemaga mos.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    private function mainColumns(array $config): array
    {
        $columns = array_filter(array_values($config['columns']));

        if ($config['layout'] === 'json') {
            $columns = [...$columns, ...array_filter(array_values($config['json']))];
        }

        foreach (array_keys((array) config('translation.locale_names')) as $locale) {
            if ($config['layout'] === 'columns') {
                foreach (array_filter($config['suffixed']) as $template) {
                    $columns[] = str_replace('{locale}', $locale, $template);
                }
            }
        }

        foreach (array_merge($config['defaults']['common'] ?? [], $config['defaults']['news'] ?? [], $config['defaults']['announcement'] ?? []) as $column => $value) {
            $columns[] = $column;
        }

        return array_values(array_unique($columns));
    }

    /**
     * `columns` joylashuvida barcha til ustunlari bo'lmasligi normal —
     * yo'qlari ogohlantirish bo'lib chiqadi, xato emas.
     *
     * @param  array<int, string>  $expected
     */
    private function checkTable(string $connection, string $table, array $expected): void
    {
        $this->newLine();

        if (! Schema::connection($connection)->hasTable($table)) {
            $this->components->twoColumnDetail("<fg=red>{$table}</>", 'jadval topilmadi');
            $this->failed = true;

            return;
        }

        $actual = collect(Schema::connection($connection)->getColumns($table))->pluck('name');
        $missing = collect($expected)->reject(fn (string $column) => $actual->contains($column));

        if ($missing->isEmpty()) {
            $this->components->twoColumnDetail("<fg=green>{$table}</>", count($expected).' ta ustun joyida');

            return;
        }

        $this->components->twoColumnDetail("<fg=red>{$table}</>", "yo'q ustunlar: {$missing->implode(', ')}");
        $this->line('  mavjud ustunlar: '.$actual->implode(', '));
        $this->failed = true;
    }

    /**
     * Saytda majburiy (NOT NULL, standart qiymatsiz) ustun bo'lsa-yu, biz unga
     * qiymat yozmasak, INSERT xato beradi. Shuni oldindan aytamiz.
     *
     * @param  array<string, mixed>  $config
     */
    private function checkRequiredColumnsAreCovered(array $config): void
    {
        if (! Schema::connection($config['connection'])->hasTable($config['table'])) {
            return;
        }

        $written = collect($this->mainColumns($config))->push($config['primary_key']);

        if ($config['layout'] === 'translations') {
            $written = $written->merge(array_values($config['translations']['columns']));
        }

        $uncovered = collect(Schema::connection($config['connection'])->getColumns($config['table']))
            ->reject(fn (array $column) => ($column['nullable'] ?? true))
            ->reject(fn (array $column) => filled($column['default'] ?? null))
            ->reject(fn (array $column) => $column['auto_increment'] ?? false)
            ->pluck('name')
            ->reject(fn (string $name) => $written->contains($name));

        if ($uncovered->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->components->warn("Bu ustunlar majburiy, lekin biz ularga yozmaymiz: {$uncovered->implode(', ')}");
        $this->line("  config/site.php -> database.defaults ga doimiy qiymat qo'shing.");
        $this->failed = true;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function checkStorage(array $config): void
    {
        $this->newLine();
        $path = $config['storage']['path'] ?? null;

        if (blank($path)) {
            $this->components->warn('SITE_STORAGE_PATH berilmagan — rasmlar saytga ko\'chirilmaydi, faqat matn chop etiladi.');

            return;
        }

        $directory = rtrim((string) $path, '/').'/'.trim((string) $config['storage']['subdirectory'], '/');

        match (true) {
            ! is_dir($path) => $this->reportProblem("Papka topilmadi: {$path}"),
            is_dir($directory) && ! is_writable($directory) => $this->reportProblem("Papkaga yozib bo'lmaydi: {$directory}"),
            ! is_dir($directory) && ! is_writable($path) => $this->reportProblem("Papkaga yozib bo'lmaydi: {$path}"),
            default => $this->components->twoColumnDetail('<fg=green>rasm papkasi</>', Str::limit($directory, 60)),
        };
    }

    private function reportProblem(string $message): void
    {
        $this->components->error($message);
        $this->failed = true;
    }
}
