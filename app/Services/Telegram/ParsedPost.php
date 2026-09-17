<?php

namespace App\Services\Telegram;

use App\Enums\PostType;
use Illuminate\Support\Carbon;

/** Telegram `channel_post` obyektidan ajratib olingan ma'lumot. */
class ParsedPost
{
    /**
     * @param  array<int, array{file_id: string, file_unique_id: string, kind: string, mime: ?string, size: ?int}>  $media
     */
    public function __construct(
        public readonly int $chatId,
        public readonly int $messageId,
        public readonly ?string $mediaGroupId,
        public readonly ?PostType $type,
        public readonly string $text,
        public readonly string $html,
        public readonly array $media,
        public readonly ?Carbon $postedAt,
        public readonly ?string $url,
    ) {}

    /** Post qayta ishlashga arziydimi: turi aniqlangan va matn yoki rasm bor. */
    public function isRoutable(): bool
    {
        return $this->type !== null && (trim($this->text) !== '' || $this->media !== []);
    }
}
