<?php

namespace App\Services\Translation;

use RuntimeException;

/**
 * Barcha dvigatellar uchun umumiy prompt, JSON sxemasi va javobni o'qish.
 *
 * Prompt bitta joyda turadi, shunda dvigatel almashtirilganda tarjima
 * uslubi ham, chiqish shakli ham o'zgarmaydi.
 */
class TranslationPrompt
{
    /**
     * Manba tili ham ro'yxatga kiradi: u yerda tarjima emas, Telegram
     * uslubidan sayt uslubiga keltirilgan matn qaytadi.
     *
     * @param  array<int, string>  $targetLocales
     * @return array<int, string>
     */
    public function locales(string $sourceLocale, array $targetLocales): array
    {
        return array_values(array_unique([$sourceLocale, ...$targetLocales]));
    }

    public function system(): string
    {
        return <<<'PROMPT'
            Sen oliy ta'lim muassasasining matbuot xizmati uchun ishlaydigan tarjimonsan.
            Vazifang: institut Telegram kanalidagi e'lon va yangilik matnini saytga
            joylashtirish uchun tayyorlash va boshqa tillarga tarjima qilish.

            Qat'iy qoidalar:
            1. Faktlarni o'zgartirma va yangisini o'ylab topma. Sana, vaqt, manzil, ism,
               lavozim, telefon raqam, havola va raqamlar asl holida qolsin.
            2. Kirish HTML bo'lagi beriladi. Chiqishda ham faqat shu teglardan foydalan:
               <p>, <br>, <strong>, <em>, <u>, <s>, <a href>, <ul>, <ol>, <li>,
               <blockquote>, <code>, <pre>. Boshqa teg, atribut, style yoki script ishlatma.
               Havolalarning href qiymati aynan o'zgarmasin.
            3. Sarlavha (title) matnning o'zidan kelib chiqsin — 120 belgidan oshmasin,
               nuqta bilan tugamasin, hissiyotli yoki reklama uslubida bo'lmasin.
               Agar matnda tayyor sarlavha bo'lsa, o'shani ishlat.
            4. Qisqacha mazmun (summary) — 300 belgigacha bir-ikki gap, HTML teglarsiz.
            5. Atamalarni barcha tillarda bir xil tarjima qil. Rasmiy uslubni saqla.
               Institut, fakultet, kafedra va tadbir nomlarini o'sha muassasada qabul
               qilingan rasmiy shaklda yoz.
            6. Emoji va Telegramga xos chaqiriqlarni ("kanalga obuna bo'ling", "👇",
               "@username", "havolani bosing") saytga mos bo'lmagani uchun olib tashla.
            7. Manba tili uchun ham yozuv qaytar: u yerda tarjima emas, faqat tozalangan
               va sayt uslubiga keltirilgan matn bo'lsin.

            Javobni faqat so'ralgan JSON sxemasi bo'yicha qaytar.
            PROMPT;
    }

    /** @param  array<int, string>  $locales */
    public function user(string $sourceHtml, string $postType, string $sourceLocale, array $locales): string
    {
        $names = (array) config('translation.locale_names');
        $typeLabel = $postType === 'announcement' ? "e'lon" : 'yangilik';
        $sourceName = $names[$sourceLocale] ?? $sourceLocale;

        $wanted = [];
        foreach ($locales as $locale) {
            $wanted[] = "- \"{$locale}\" — ".($names[$locale] ?? $locale).' tili';
        }

        $wanted = implode("\n", $wanted);

        return <<<PROMPT
            Post turi: {$typeLabel}
            Manba tili: {$sourceName}

            Kerakli tillar:
            {$wanted}

            Manba matni (HTML):
            <<<HTML
            {$sourceHtml}
            HTML
            PROMPT;
    }

    /**
     * JSON sxemasi. Gemini OpenAPI qism to'plamidan foydalanadi va
     * `additionalProperties` ni qabul qilmaydi, shuning uchun u o'chiriladi.
     *
     * @param  array<int, string>  $locales
     * @return array<string, mixed>
     */
    public function schema(array $locales, bool $allowAdditionalPropertiesKeyword = true): array
    {
        $entry = [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Sarlavha, 120 belgigacha, nuqtasiz'],
                'summary' => ['type' => 'string', 'description' => 'Qisqacha mazmun, 300 belgigacha, HTML teglarsiz'],
                'body_html' => ['type' => 'string', 'description' => 'Asosiy matn, ruxsat etilgan HTML teglar bilan'],
            ],
            'required' => ['title', 'summary', 'body_html'],
        ];

        if ($allowAdditionalPropertiesKeyword) {
            $entry['additionalProperties'] = false;
        }

        $properties = [];
        foreach ($locales as $locale) {
            $properties[$locale] = $entry + ['description' => "{$locale} tilidagi variant"];
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
            'required' => $locales,
        ];

        if ($allowAdditionalPropertiesKeyword) {
            $schema['additionalProperties'] = false;
        }

        return $schema;
    }

    /**
     * Modeldan kelgan JSON matnini tekshirib, normal ko'rinishga keltiradi.
     *
     * @param  array<int, string>  $locales
     * @return array<string, array{title: string, summary: string, body_html: string}>
     */
    public function decode(string $json, array $locales, string $driver): array
    {
        $decoded = json_decode($this->stripCodeFence($json), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("{$driver} javobini JSON sifatida o'qib bo'lmadi.");
        }

        $result = [];

        foreach ($locales as $locale) {
            $item = $decoded[$locale] ?? null;

            if (! is_array($item) || blank($item['title'] ?? null) || blank($item['body_html'] ?? null)) {
                throw new RuntimeException("{$driver} javobida '{$locale}' tili uchun to'liq tarjima yo'q.");
            }

            $result[$locale] = [
                'title' => trim((string) $item['title']),
                'summary' => trim((string) ($item['summary'] ?? '')),
                'body_html' => trim((string) $item['body_html']),
            ];
        }

        return $result;
    }

    /**
     * Ba'zi modellar JSONni ```json ... ``` ichiga o'rab yuboradi.
     */
    private function stripCodeFence(string $json): string
    {
        $trimmed = trim($json);

        if (! str_starts_with($trimmed, '```')) {
            return $trimmed;
        }

        $withoutOpening = preg_replace('/^```[a-zA-Z]*\s*/', '', $trimmed) ?? $trimmed;

        return trim(preg_replace('/```$/', '', trim($withoutOpening)) ?? $withoutOpening);
    }

    public function assertNotEmpty(string $sourceHtml): void
    {
        if (trim(strip_tags($sourceHtml)) === '') {
            throw new RuntimeException("Tarjima uchun matn bo'sh.");
        }
    }
}
