<?php

namespace App\Services\Translation;

use App\Contracts\TranslationDriver;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenAI `chat/completions` shakliga mos har qanday servis uchun dvigatel.
 *
 * Shu bitta dvigatel bilan OpenRouter (bepul modellar), Groq, Together,
 * Mistral va lokal Ollama ishlaydi — faqat `base_url` va `model`
 * o'zgartiriladi.
 */
class OpenAiCompatibleTranslator implements TranslationDriver
{
    public function __construct(private readonly TranslationPrompt $prompt) {}

    public function name(): string
    {
        return 'openai_compatible';
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

        $base = rtrim((string) config('translation.openai_compatible.base_url'), '/');

        if (blank($base)) {
            throw new RuntimeException('TRANSLATE_OPENAI_BASE_URL sozlanmagan.');
        }

        $locales = $this->prompt->locales($sourceLocale, $targetLocales);

        $request = Http::timeout((int) config('translation.openai_compatible.timeout'))->asJson();
        $key = config('translation.openai_compatible.api_key');

        if (filled($key)) {
            $request = $request->withToken((string) $key);
        }

        $response = $request->post("{$base}/chat/completions", [
            'model' => (string) config('translation.openai_compatible.model'),
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $this->systemWithSchema($locales)],
                ['role' => 'user', 'content' => $this->prompt->user($sourceHtml, $postType, $sourceLocale, $locales)],
            ],
        ]);

        if ($response->status() === 429) {
            throw new RuntimeException('Servis limiti tugadi — keyinroq qayta uriniladi.');
        }

        if (! $response->successful()) {
            $reason = data_get($response->json(), 'error.message') ?? mb_substr($response->body(), 0, 300);

            throw new RuntimeException("Tarjima servisi xatosi (HTTP {$response->status()}): {$reason}");
        }

        $text = data_get($response->json(), 'choices.0.message.content');

        if (! is_string($text) || trim($text) === '') {
            throw new RuntimeException("Tarjima servisi javobida matn yo'q.");
        }

        return $this->prompt->decode($text, $locales, 'Tarjima servisi');
    }

    /**
     * `json_object` rejimi sxemani majburlamaydi — shuning uchun kutilgan
     * shakl promptning o'ziga qo'shib yuboriladi.
     *
     * @param  array<int, string>  $locales
     */
    private function systemWithSchema(array $locales): string
    {
        $schema = json_encode(
            $this->prompt->schema($locales),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return $this->prompt->system()."\n\nJavob aynan shu JSON sxemasiga mos bo'lsin:\n{$schema}";
    }
}
