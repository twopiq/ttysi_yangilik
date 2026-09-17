<?php

namespace App\Services\Translation;

use Anthropic\Client;
use App\Contracts\TranslationDriver;
use RuntimeException;

/**
 * Anthropic Claude orqali tarjima qiladi (pullik API kaliti talab qilinadi).
 *
 * Barcha tillar bitta so'rovga jamlanadi: atamalar tillar bo'ylab bir xil
 * qoladi va so'rovlar soni kamayadi.
 */
class ClaudeTranslator implements TranslationDriver
{
    public function __construct(
        private readonly TranslationPrompt $prompt,
        private readonly ?Client $client = null,
    ) {}

    public function name(): string
    {
        return 'claude';
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

        $locales = $this->prompt->locales($sourceLocale, $targetLocales);

        $message = $this->client()->messages->create(
            model: (string) config('translation.claude.model'),
            maxTokens: (int) config('translation.claude.max_tokens'),
            system: [[
                'type' => 'text',
                'text' => $this->prompt->system(),
                'cacheControl' => ['type' => 'ephemeral'],
            ]],
            messages: [[
                'role' => 'user',
                'content' => $this->prompt->user($sourceHtml, $postType, $sourceLocale, $locales),
            ]],
            outputConfig: [
                'effort' => (string) config('translation.claude.effort'),
                'format' => [
                    'type' => 'json_schema',
                    'schema' => $this->prompt->schema($locales),
                ],
            ],
        );

        if ($message->stopReason === 'refusal') {
            $explanation = $message->stopDetails->explanation ?? "sababi ko'rsatilmagan";

            throw new RuntimeException("Model tarjimadan bosh tortdi: {$explanation}");
        }

        if ($message->stopReason === 'max_tokens') {
            throw new RuntimeException('Javob max_tokens chegarasida uzilib qoldi — post juda uzun.');
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return $this->prompt->decode($block->text, $locales, 'Claude');
            }
        }

        throw new RuntimeException("Claude javobida matn bloki yo'q.");
    }

    private function client(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $key = config('translation.claude.api_key');

        if (blank($key)) {
            throw new RuntimeException('ANTHROPIC_API_KEY sozlanmagan.');
        }

        return new Client(apiKey: $key);
    }
}
