<?php

namespace App\Services\Translation;

use Anthropic\Client;
use RuntimeException;

/**
 * Bitta Claude chaqirig'ida postni barcha tillarga tarjima qiladi.
 *
 * Bitta so'rovda qilinishining sababi: atamalar (fakultet, kafedra, lavozim
 * nomlari) tillar bo'ylab bir xil qoladi va so'rov soni kamayadi.
 */
class Translator
{
    public function __construct(private readonly ?Client $client = null) {}

    private function client(): Client
    {
        $key = config('anthropic.api_key');

        if (blank($key)) {
            throw new RuntimeException('ANTHROPIC_API_KEY sozlanmagan.');
        }

        return $this->client ?? new Client(apiKey: $key);
    }

    /**
     * @param  array<int, string>  $targetLocales
     * @return array<string, array{title: string, summary: string, body_html: string}>
     */
    public function translate(
        string $sourceHtml,
        string $postType,
        string $sourceLocale,
        array $targetLocales,
    ): array {
        if (trim(strip_tags($sourceHtml)) === '') {
            throw new RuntimeException("Tarjima uchun matn bo'sh.");
        }

        $locales = array_values(array_unique([$sourceLocale, ...$targetLocales]));

        $message = $this->client()->messages->create(
            model: (string) config('anthropic.model'),
            maxTokens: (int) config('anthropic.max_tokens'),
            system: [[
                'type' => 'text',
                'text' => $this->systemPrompt(),
                // Tizim prompti o'zgarmaydi — keshlansa har so'rovda puli tejaladi.
                'cacheControl' => ['type' => 'ephemeral'],
            ]],
            messages: [[
                'role' => 'user',
                'content' => $this->userPrompt($sourceHtml, $postType, $sourceLocale, $locales),
            ]],
            outputConfig: [
                'effort' => (string) config('anthropic.effort'),
                'format' => [
                    'type' => 'json_schema',
                    'schema' => $this->schema($locales),
                ],
            ],
        );

        if ($message->stopReason === 'refusal') {
            $explanation = $message->stopDetails->explanation ?? 'sababi korsatilmagan';

            throw new RuntimeException("Model tarjimadan bosh tortdi: {$explanation}");
        }

        if ($message->stopReason === 'max_tokens') {
            throw new RuntimeException('Javob max_tokens chegarasida uzilib qoldi — post juda uzun.');
        }

        return $this->decode($message, $locales);
    }

    private function decode(object $message, array $locales): array
    {
        $json = null;
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $json = $block->text;
                break;
            }
        }

        if ($json === null) {
            throw new RuntimeException('Claude javobida matn bloki yoq.');
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Claude javobini JSON sifatida oqib bolmadi.');
        }

        $result = [];
        foreach ($locales as $locale) {
            $item = $decoded[$locale] ?? null;

            if (! is_array($item) || blank($item['title'] ?? null) || blank($item['body_html'] ?? null)) {
                throw new RuntimeException("Javobda '{$locale}' tili uchun toliq tarjima yoq.");
            }

            $result[$locale] = [
                'title' => trim((string) $item['title']),
                'summary' => trim((string) ($item['summary'] ?? '')),
                'body_html' => trim((string) $item['body_html']),
            ];
        }

        return $result;
    }

    private function systemPrompt(): string
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

    private function userPrompt(string $sourceHtml, string $postType, string $sourceLocale, array $locales): string
    {
        $names = (array) config('anthropic.locale_names');
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

    /** @param  array<int, string>  $locales */
    private function schema(array $locales): array
    {
        $properties = [];

        foreach ($locales as $locale) {
            $properties[$locale] = [
                'type' => 'object',
                'description' => "{$locale} tilidagi variant",
                'properties' => [
                    'title' => [
                        'type' => 'string',
                        'description' => 'Sarlavha, 120 belgigacha, nuqtasiz',
                    ],
                    'summary' => [
                        'type' => 'string',
                        'description' => 'Qisqacha mazmun, 300 belgigacha, HTML teglarsiz',
                    ],
                    'body_html' => [
                        'type' => 'string',
                        'description' => 'Asosiy matn, ruxsat etilgan HTML teglar bilan',
                    ],
                ],
                'required' => ['title', 'summary', 'body_html'],
                'additionalProperties' => false,
            ];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $locales,
            'additionalProperties' => false,
        ];
    }
}
