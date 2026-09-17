<?php

namespace Tests\Support;

use App\Contracts\TranslationDriver;
use RuntimeException;

/**
 * Testlarda haqiqiy tarjima servisi o'rniga ishlatiladi.
 *
 * Chaqirilgan argumentlarni saqlaydi, shunda quvur dvigatelga to'g'ri
 * ma'lumot uzatayotgani tekshiriladi.
 */
class FakeTranslationDriver implements TranslationDriver
{
    /** @var array<string, mixed>|null */
    public ?array $lastCall = null;

    /**
     * @param  array<string, array{title: string, summary: string, body_html: string}>  $result
     */
    public function __construct(
        private readonly array $result = [],
        private readonly ?RuntimeException $failure = null,
    ) {}

    public function name(): string
    {
        return 'fake';
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
        $this->lastCall = compact('sourceHtml', 'postType', 'sourceLocale', 'targetLocales');

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->result;
    }
}
