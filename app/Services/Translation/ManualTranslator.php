<?php

namespace App\Services\Translation;

use App\Contracts\TranslationDriver;
use Illuminate\Support\Str;

/**
 * Hech qanday API ishlatmaydigan dvigatel: tarjimani moderator o'zi yozadi.
 *
 * Har bir til uchun manba matnining nusxasi tayyorlanadi va post odatdagidek
 * moderatsiya paneliga tushadi — moderator o'sha yerda tarjima qiladi.
 * Quvurning qolgan qismi (rasm, tasdiqlash, saytga yuborish) o'zgarmaydi.
 */
class ManualTranslator implements TranslationDriver
{
    public function __construct(private readonly TranslationPrompt $prompt) {}

    public function name(): string
    {
        return 'manual';
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
        $this->prompt->assertNotEmpty($sourceHtml);

        $draft = [
            'title' => $this->titleFrom($sourceHtml),
            'summary' => '',
            'body_html' => $sourceHtml,
        ];

        $result = [];

        foreach ($this->prompt->locales($sourceLocale, $targetLocales) as $locale) {
            $result[$locale] = $draft;
        }

        return $result;
    }

    /**
     * Sarlavha matnning birinchi qatoridan olinadi — moderator uni panelda
     * tuzatadi.
     */
    private function titleFrom(string $sourceHtml): string
    {
        $text = trim(html_entity_decode(strip_tags(
            preg_replace('/<\/(p|br|li|blockquote)>/i', "\n", $sourceHtml) ?? $sourceHtml,
        ), ENT_QUOTES | ENT_HTML5));

        $firstLine = trim((string) strtok($text, "\n"));

        return Str::limit($firstLine !== '' ? $firstLine : $text, 110, '');
    }
}
