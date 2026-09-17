<?php

namespace App\Services\Translation;

use App\Contracts\TranslationDriver;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Gemini API orqali tarjima qiladi.
 *
 * Bepul daraja mavjud va kundalik limiti institut kanali hajmidan ancha
 * yuqori. Javob shakli `responseSchema` bilan kafolatlanadi.
 */
class GeminiTranslator implements TranslationDriver
{
    public function __construct(private readonly TranslationPrompt $prompt) {}

    public function name(): string
    {
        return 'gemini';
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

        $key = config('translation.gemini.api_key');

        if (blank($key)) {
            throw new RuntimeException('GEMINI_API_KEY sozlanmagan.');
        }

        $locales = $this->prompt->locales($sourceLocale, $targetLocales);
        $model = (string) config('translation.gemini.model');
        $base = rtrim((string) config('translation.gemini.base_url'), '/');

        $response = Http::timeout((int) config('translation.gemini.timeout'))
            ->withHeaders(['x-goog-api-key' => $key])
            ->asJson()
            ->post("{$base}/models/{$model}:generateContent", [
                'systemInstruction' => [
                    'parts' => [['text' => $this->prompt->system()]],
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => $this->prompt->user($sourceHtml, $postType, $sourceLocale, $locales)]],
                ]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    // Gemini OpenAPI qism to'plamini ishlatadi: additionalProperties yo'q.
                    'responseSchema' => $this->prompt->schema($locales, allowAdditionalPropertiesKeyword: false),
                    'temperature' => 0.2,
                ],
            ]);

        if ($response->status() === 429) {
            throw new RuntimeException('Gemini bepul darajasining limiti tugadi — keyinroq qayta uriniladi.');
        }

        if (! $response->successful()) {
            $reason = data_get($response->json(), 'error.message') ?? mb_substr($response->body(), 0, 300);

            throw new RuntimeException("Gemini xatosi (HTTP {$response->status()}): {$reason}");
        }

        $finishReason = data_get($response->json(), 'candidates.0.finishReason');

        if ($finishReason !== null && ! in_array($finishReason, ['STOP', 'MAX_TOKENS'], true)) {
            throw new RuntimeException("Gemini javobni tugatmadi: {$finishReason}");
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            throw new RuntimeException("Gemini javobida matn yo'q.");
        }

        return $this->prompt->decode($text, $locales, 'Gemini');
    }
}
