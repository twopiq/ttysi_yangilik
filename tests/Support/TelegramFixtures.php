<?php

namespace Tests\Support;

/** Telegram Bot API yuboradigan update'larning namunalari. */
class TelegramFixtures
{
    public static function textPost(array $overrides = []): array
    {
        return array_replace_recursive([
            'update_id' => 100001,
            'channel_post' => [
                'message_id' => 55,
                'chat' => ['id' => -1001234567890, 'username' => 'institut_kanali', 'type' => 'channel'],
                'date' => 1758067200,
                'text' => "#yangilik\nOchiq eshiklar kuni\n\nAbituriyentlar uchun uchrashuv bo'lib o'tadi.",
                'entities' => [
                    ['type' => 'hashtag', 'offset' => 0, 'length' => 9],
                    ['type' => 'bold', 'offset' => 10, 'length' => 19],
                ],
            ],
        ], $overrides);
    }

    public static function albumPart(int $updateId, int $messageId, string $fileUid, ?string $caption = null): array
    {
        $post = [
            'message_id' => $messageId,
            'chat' => ['id' => -1001234567890, 'username' => 'institut_kanali', 'type' => 'channel'],
            'date' => 1758067200,
            'media_group_id' => '13000000000000001',
            'photo' => [
                ['file_id' => "small-{$fileUid}", 'file_unique_id' => "su-{$fileUid}", 'file_size' => 900],
                ['file_id' => "big-{$fileUid}", 'file_unique_id' => "bu-{$fileUid}", 'file_size' => 90000],
            ],
        ];

        if ($caption !== null) {
            $post['caption'] = $caption;
            // Hashtag uzunligi UTF-16 birliklarida — izohning birinchi so'zidan olinadi.
            $hashtag = strtok($caption, " \n");
            $post['caption_entities'] = [[
                'type' => 'hashtag',
                'offset' => 0,
                'length' => strlen(mb_convert_encoding($hashtag, 'UTF-16LE', 'UTF-8')) / 2,
            ]];
        }

        return ['update_id' => $updateId, 'channel_post' => $post];
    }
}
