<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sayt bazasining tuzilishini o'qib beradi.
 *
 * Sxemani hech kimdan so'rashning hojati yo'q — baza o'zi aytadi. Buyruq
 * faqat o'qiydi: jadvallarni topadi, yangilikka o'xshaganlarini ajratadi,
 * ustunlarini chiqaradi va ko'p tillilik qanday saqlanganini aniqlaydi.
 */
class InspectSiteDatabase extends Command
{
    protected $signature = 'site:inspect
                            {table? : Faqat shu jadvalni ko\'rsatish}
                            {--all : Barcha jadvallarni ro\'yxatlash}
                            {--sample : Har bir jadvaldan bitta qator namunasini ko\'rsatish}';

    protected $description = 'Institut sayti bazasining tuzilishini o\'qiydi (faqat o\'qish)';

    /** Yangilik/e'lon jadvalini topish uchun nom bo'laklari. */
    private const NEWS_HINTS = ['news', 'post', 'article', 'yangilik', 'elon', 'announce', 'blog', 'novost'];

    /** Ko'p tillilikni bildiruvchi ustun nomlari. */
    private const LOCALE_HINTS = ['locale', 'lang', 'language'];

    public function handle(): int
    {
        try {
            $this->line('Ulanish tekshirilmoqda...');
            DB::connection('site')->getPdo();
        } catch (Throwable $e) {
            $this->components->error('Sayt bazasiga ulanib bo\'lmadi.');
            $this->line($e->getMessage());
            $this->newLine();
            $this->line('.env da SITE_DB_HOST, SITE_DB_DATABASE, SITE_DB_USERNAME, SITE_DB_PASSWORD to\'ldirilganini tekshiring.');

            return self::FAILURE;
        }

        $tables = collect(Schema::connection('site')->getTables())
            ->pluck('name')
            ->sort()
            ->values();

        $this->components->info("Ulanish muvaffaqiyatli. Jami {$tables->count()} ta jadval.");

        if ($table = $this->argument('table')) {
            if (! $tables->contains($table)) {
                $this->components->error("'{$table}' jadvali topilmadi.");

                return self::FAILURE;
            }

            $this->describe($table);

            return self::SUCCESS;
        }

        if ($this->option('all')) {
            $this->newLine();
            $this->line($tables->implode(', '));
        }

        $candidates = $tables->filter(fn (string $name) => Str::contains(Str::lower($name), self::NEWS_HINTS));

        if ($candidates->isEmpty()) {
            $this->newLine();
            $this->components->warn('Yangilikka o\'xshagan jadval topilmadi. Ro\'yxatni ko\'rish uchun: --all');

            return self::SUCCESS;
        }

        foreach ($candidates as $name) {
            $this->describe($name);
        }

        $this->suggestLayout($candidates);

        return self::SUCCESS;
    }

    private function describe(string $table): void
    {
        $columns = Schema::connection('site')->getColumns($table);

        $this->newLine();
        $this->components->twoColumnDetail("<fg=cyan>{$table}</>", count($columns).' ta ustun');

        $rows = [];
        foreach ($columns as $column) {
            $rows[] = [
                $column['name'],
                $column['type'],
                ($column['nullable'] ?? false) ? 'null bo\'lishi mumkin' : 'majburiy',
                $this->shorten($column['default'] ?? null),
            ];
        }

        $this->table(['Ustun', 'Turi', 'Majburiyligi', 'Standart qiymat'], $rows);

        foreach (Schema::connection('site')->getForeignKeys($table) as $foreignKey) {
            $columns = implode(', ', $foreignKey['columns']);
            $target = $foreignKey['foreign_table'].'('.implode(', ', $foreignKey['foreign_columns']).')';
            $this->line("  tashqi kalit: {$columns} -> {$target}");
        }

        if ($this->option('sample')) {
            $this->showSample($table);
        }
    }

    private function showSample(string $table): void
    {
        $row = DB::connection('site')->table($table)->first();

        if ($row === null) {
            $this->line('  (jadval bo\'sh)');

            return;
        }

        $this->line('  namuna qator:');
        foreach ((array) $row as $key => $value) {
            $this->line("    {$key}: ".$this->shorten(is_scalar($value) || $value === null ? $value : json_encode($value)));
        }
    }

    /**
     * Ko'p tillilik uch xil saqlanadi: alohida translations jadvali,
     * JSON ustunlar yoki til qo'shimchali ustunlar. Qaysi biri ekanini
     * ustun nomlaridan aniqlaymiz.
     *
     * @param  Collection<int, string>  $candidates
     */
    private function suggestLayout(Collection $candidates): void
    {
        $this->newLine();
        $this->components->info('Ko\'p tillilik tahlili');

        foreach ($candidates as $table) {
            $columns = collect(Schema::connection('site')->getColumns($table));
            $names = $columns->pluck('name');

            $hasLocaleColumn = $names->contains(
                fn (string $name) => in_array(Str::lower($name), self::LOCALE_HINTS, true)
            );

            $jsonColumns = $columns
                ->filter(fn (array $c) => Str::contains(Str::lower((string) $c['type']), ['json', 'jsonb']))
                ->pluck('name');

            $suffixed = $names->filter(
                fn (string $name) => (bool) preg_match('/_(uz|ru|en|uz_cyrl|kr)$/i', $name)
            );

            $layout = match (true) {
                $hasLocaleColumn => "translations — '{$table}' qatorlari til bo'yicha ajratilgan",
                $jsonColumns->isNotEmpty() => "json — JSON ustunlar: {$jsonColumns->implode(', ')}",
                $suffixed->isNotEmpty() => "columns — til qo'shimchali ustunlar: {$suffixed->implode(', ')}",
                default => 'aniqlanmadi — bu jadval bir tilli bo\'lishi mumkin',
            };

            $this->components->twoColumnDetail($table, $layout);
        }

        $this->newLine();
        $this->line('Topilganini config/site.php ichidagi `database` bo\'limiga ko\'chiring.');
    }

    private function shorten(mixed $value): string
    {
        return $value === null ? '—' : Str::limit((string) $value, 40);
    }
}
