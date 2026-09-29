<?php

namespace App\Console\Commands;

use App\Services\Site\AdminPanel\AdminPanelClient;
use App\Services\Site\AdminPanel\FormParser;
use App\Services\Site\AdminPanel\HtmlField;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Admin panelga kirib, "yangilik qo'shish" formasining maydonlarini o'qiydi.
 *
 * Hech narsa yubormaydi. Maqsadi — maydon nomlarini kimdandir so'ramasdan,
 * panelning o'zidan bilish.
 */
class InspectSiteForm extends Command
{
    protected $signature = 'site:form
                            {path? : Forma sahifasi (standart: config admin.create_path)}
                            {--login : Faqat kirish formasini ko\'rsatish}
                            {--html : Sahifa HTML\'ini faylga saqlash}
                            {--file= : Panelga ulanmasdan, saqlangan HTML fayldan o\'qish}';

    protected $description = 'Admin paneldagi forma maydonlarini o\'qiydi (hech narsa yubormaydi)';

    /** Panel qanday texnologiyada ekanini bildiruvchi izlar. */
    private const STACK_MARKERS = [
        'Livewire' => ['wire:id', 'wire:model', 'livewire'],
        'Inertia (Vue/React)' => ['data-page=', 'inertia'],
        'Filament' => ['filament', 'fi-btn'],
        'Nova' => ['nova-', 'window.Nova'],
    ];

    public function handle(AdminPanelClient $client, FormParser $parser): int
    {
        $config = (array) config('site.admin');

        if (filled($file = $this->option('file'))) {
            return $this->showSavedFile($parser, (string) $file, $config);
        }

        if (blank($config['base_url'])) {
            $this->components->error('SITE_ADMIN_URL sozlanmagan.');

            return self::FAILURE;
        }

        try {
            return $this->option('login')
                ? $this->showLoginForm($parser, $config)
                : $this->showCreateForm($client, $parser, $config);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Brauzerdan saqlangan sahifadan o'qiydi.
     *
     * Saytga tarmoq orqali yetib bo'lmasa (yoki panel JavaScript bilan
     * yasalgan bo'lsa) — sahifani brauzerda saqlab, shu yerda tahlil qilish
     * mumkin. Tarmoq ham, login ham kerak emas.
     *
     * @param  array<string, mixed>  $config
     */
    private function showSavedFile(FormParser $parser, string $file, array $config): int
    {
        if (! is_file($file)) {
            $this->components->error("Fayl topilmadi: {$file}");

            return self::FAILURE;
        }

        $html = (string) file_get_contents($file);
        $base = (string) ($config['base_url'] ?: 'https://example.test');

        $this->components->info('Saqlangan fayldan o\'qilmoqda: '.basename($file));

        $this->detectStack($html);

        $login = $parser->pickLogin($html, $base);

        if ($login !== null) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=cyan>kirish formasi</>', $login->action);
            $this->showFields($login->fields);
        }

        $forms = array_values(array_filter(
            $parser->forms($html, $base),
            fn ($form) => $form->passwordFields() === [],
        ));

        if ($forms === []) {
            $this->components->error('Faylda kirishdan boshqa forma topilmadi.');

            return $login === null ? self::FAILURE : self::SUCCESS;
        }

        $this->newLine();
        $this->line("Faylda {$this->count($forms)}.");

        foreach ($forms as $index => $form) {
            $this->newLine();
            $this->components->twoColumnDetail(
                '<fg=cyan>forma #'.($index + 1).'</>',
                "{$form->method} {$form->action}".($form->multipart ? ' · fayl qabul qiladi' : ''),
            );
            $this->showFields($form->fields);
        }

        $chosen = $parser->pick($forms, $config['action_hint'] ?? null);

        if ($chosen !== null) {
            $this->newLine();
            $this->components->info("Tanlanadigan forma: {$chosen->method} {$chosen->action}");
            $this->suggestMapping($chosen->fields);
        }

        return self::SUCCESS;
    }

    /**
     * Kirish formasi kirishdan oldin ochiladi — parol maydoni nomi
     * noto'g'ri bo'lsa, boshqa hech narsa ishlamaydi.
     *
     * @param  array<string, mixed>  $config
     */
    private function showLoginForm(FormParser $parser, array $config): int
    {
        $url = rtrim((string) $config['base_url'], '/').'/'.ltrim((string) $config['login_path'], '/');
        $html = Http::timeout((int) $config['timeout'])->get($url)->body();

        $form = $parser->pickLogin($html, $url);

        if ($form === null) {
            $this->components->error("Kirish formasi topilmadi: {$url}");

            return self::FAILURE;
        }

        $this->components->info('Kirish formasi');
        $this->components->twoColumnDetail('action', $form->action);
        $this->showFields($form->fields);

        $this->newLine();
        $this->line('config/site.php -> admin.login_fields ga shu nomlarni yozing.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function showCreateForm(AdminPanelClient $client, FormParser $parser, array $config): int
    {
        $path = $this->argument('path') ?? $config['create_path'];

        $this->line('Panelga kirilmoqda...');
        $response = $client->page($path);
        $html = $response->body();

        $this->components->info('Kirish muvaffaqiyatli.');

        $this->detectStack($html);

        if ($this->option('html')) {
            $file = storage_path('app/site-form.html');
            file_put_contents($file, $html);
            $this->line("HTML saqlandi: {$file}");
        }

        $url = (string) ($response->effectiveUri() ?? $path);
        $forms = $parser->forms($html, $url);

        if ($forms === []) {
            $this->components->error('Sahifada birorta forma topilmadi.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("Sahifada {$this->count($forms)}.");

        foreach ($forms as $index => $form) {
            $this->newLine();
            $this->components->twoColumnDetail(
                '<fg=cyan>forma #'.($index + 1).'</>',
                "{$form->method} {$form->action}".($form->multipart ? ' · fayl qabul qiladi' : ''),
            );
            $this->showFields($form->fields);
        }

        $chosen = $parser->pick($forms, $config['action_hint'] ?? null);

        if ($chosen !== null) {
            $this->newLine();
            $this->components->info("Tanlanadigan forma: {$chosen->method} {$chosen->action}");
            $this->suggestMapping($chosen->fields);
        }

        return self::SUCCESS;
    }

    /** @param  array<int, mixed>  $forms */
    private function count(array $forms): string
    {
        return count($forms).' ta forma bor';
    }

    /** @param  array<string, HtmlField>  $fields */
    private function showFields(array $fields): void
    {
        $rows = [];

        foreach ($fields as $field) {
            $rows[] = [$field->name, $field->describe()];
        }

        if ($rows === []) {
            $this->line('  (maydon yo\'q)');

            return;
        }

        $this->table(['Maydon', 'Tavsif'], $rows);
    }

    /**
     * Maydon nomlaridan taxminiy moslik yasaydi, shunda sozlamani qo'lda
     * terib chiqishga hojat qolmaydi.
     *
     * @param  array<string, HtmlField>  $fields
     */
    private function suggestMapping(array $fields): void
    {
        $names = array_keys($fields);

        $guesses = [
            'title' => $this->guess($names, ['title', 'sarlavha', 'name', 'zagolovok', 'heading']),
            'summary' => $this->guess($names, ['summary', 'description', 'excerpt', 'qisqa', 'anons', 'short']),
            'body' => $this->guess($names, ['body', 'content', 'text', 'matn', 'tekst', 'description']),
        ];

        $image = $this->guess(
            array_keys(array_filter($fields, fn (HtmlField $f) => $f->isFile())),
            ['image', 'photo', 'rasm', 'cover', 'thumbnail', 'file'],
        );

        $this->newLine();
        $this->line('Taxminiy moslik — config/site.php -> admin ga ko\'chiring va tekshirib chiqing:');
        $this->newLine();

        foreach ($guesses as $field => $guess) {
            $value = $guess === null ? 'null' : "'".$this->templatise($guess)."'";
            $this->line("    '{$field}' => {$value},");
        }

        $this->line("    'image_field' => ".($image === null ? 'null' : "'{$image}'").',');

        // `{locale}` shabloni bir nechta maydonni qoplaydi, shuning uchun
        // solishtirish shablonlashtirilgan nom bo'yicha boradi.
        $covered = array_map(fn (string $name) => $this->templatise($name), array_filter($guesses));

        $required = array_filter(
            $fields,
            fn (HtmlField $f) => $f->required
                && ! in_array($this->templatise($f->name), $covered, true)
                && ! $f->isHidden()
                && $f->name !== $image,
        );

        if ($required !== []) {
            $this->newLine();
            $this->components->warn(
                'Bu maydonlar majburiy, lekin mosliqqa tushmadi: '
                .implode(', ', array_keys($required))
            );
            $this->line('    admin.extra ga doimiy qiymat qo\'shing, masalan rubrika id.');
        }
    }

    /**
     * Maydon nomida til kodi bo'lsa (title_uz, title[ru]) uni `{locale}`
     * shabloniga aylantiradi — shunda barcha tillar to'ldiriladi.
     */
    private function templatise(string $name): string
    {
        $locales = array_keys((array) config('translation.locale_names'));

        foreach ($locales as $locale) {
            foreach (["[{$locale}]", "_{$locale}", "-{$locale}"] as $variant) {
                if (Str::endsWith($name, $variant)) {
                    return Str::beforeLast($name, $variant).str_replace($locale, '{locale}', $variant);
                }
            }
        }

        return $name;
    }

    /**
     * @param  array<int, string>  $names
     * @param  array<int, string>  $keywords
     */
    private function guess(array $names, array $keywords): ?string
    {
        foreach ($keywords as $keyword) {
            foreach ($names as $name) {
                if (Str::contains(Str::lower($name), $keyword)) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * Livewire yoki SPA panelini oddiy forma yuborish bilan boshqarib
     * bo'lmaydi — buni oldindan aytish kerak.
     */
    private function detectStack(string $html): void
    {
        $lower = Str::lower($html);
        $found = [];

        foreach (self::STACK_MARKERS as $stack => $markers) {
            if (Str::contains($lower, array_map('strtolower', $markers))) {
                $found[] = $stack;
            }
        }

        if ($found === []) {
            return;
        }

        $this->newLine();
        $this->components->warn('Panelda aniqlandi: '.implode(', ', $found));
        $this->line('  Bunday panellar formani JavaScript orqali yuboradi, oddiy POST');
        $this->line('  ishlamasligi mumkin. Agar `site:form` maydonlarni ko\'rsatsa-yu,');
        $this->line('  yuborish ishlamasa, `database` usuliga o\'tish kerak.');
    }
}
