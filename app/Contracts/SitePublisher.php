<?php

namespace App\Contracts;

use App\Models\Post;
use RuntimeException;

/**
 * Tasdiqlangan postni institut saytiga yetkazadi.
 *
 * Yetkazish usuli (HTTP API, saytning bazasiga to'g'ridan-to'g'ri yozish)
 * almashtiriladi, quvurning qolgan qismi o'zgarmaydi.
 */
interface SitePublisher
{
    /**
     * @return array{id: ?string, url: ?string}
     *
     * @throws RuntimeException nashr amalga oshmasa
     */
    public function publish(Post $post): array;

    /**
     * Postni saytdagi yozuv bilan bog'laydigan barqaror kalit.
     * Takroriy nashr yangi yozuv yaratmasligi shu kalitga tayanadi.
     */
    public function externalId(Post $post): string;

    /** Sozlamalarda ko'rsatiladigan nom, masalan "api". */
    public function name(): string;
}
