<?php

namespace App\Contracts;

use RuntimeException;

/**
 * Post matnini saytga tayyor holatga keltirib, kerakli tillarga o'giradi.
 *
 * Har bir dvigatel (Claude, Gemini, OpenAI-mos servis, qo'lda) shu
 * shartnomani bajaradi, shuning uchun quvurning qolgan qismi o'zgarmaydi.
 */
interface TranslationDriver
{
    /**
     * @param  array<int, string>  $targetLocales
     * @return array<string, array{title: string, summary: string, body_html: string}>
     *
     * @throws RuntimeException tarjima olinmasa
     */
    public function translate(
        string $sourceHtml,
        string $postType,
        string $sourceLocale,
        array $targetLocales,
    ): array;

    /** Sozlamalarda ko'rsatiladigan nom, masalan "gemini". */
    public function name(): string;
}
