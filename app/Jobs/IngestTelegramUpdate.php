<?php

namespace App\Jobs;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\TelegramUpdate;
use App\Services\Telegram\ParsedPost;
use App\Services\Telegram\PostParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Kelgan `channel_post`ni o'qib, Post yozuviga aylantiradi.
 *
 * Albom (media_group) bir nechta update bo'lib keladi va sarlavha faqat
 * birinchisida bo'ladi — shuning uchun bir media_group_id bitta Postga
 * yig'iladi, tarjima esa kechiktirilgan holda navbatga qo'yiladi.
 */
class IngestTelegramUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $telegramUpdateId) {}

    public function handle(PostParser $parser): void
    {
        $update = TelegramUpdate::find($this->telegramUpdateId);

        if ($update === null || $update->processed_at !== null) {
            return;
        }

        $payload = $update->payload;
        $message = $payload['channel_post'] ?? $payload['edited_channel_post'] ?? null;

        if (! is_array($message)) {
            $update->forceFill(['processed_at' => now()])->save();

            return;
        }

        $parsed = $parser->parse($message);

        if (! $this->fromWatchedChannel($message)) {
            Log::info('Kutilmagan kanaldan post keldi, tashlab ketildi', ['chat_id' => $parsed->chatId]);
            $update->forceFill(['processed_at' => now()])->save();

            return;
        }

        $existing = $this->findExisting($parsed);

        if ($existing === null && ! $parsed->isRoutable()) {
            // Albomda izoh (va demak hashtag) faqat bitta bo'lakda bo'ladi.
            // Agar hashtagli bo'lak hali yetib kelmagan bo'lsa, biroz kutamiz.
            if ($parsed->mediaGroupId !== null && $this->job !== null && $this->attempts() < $this->tries) {
                $this->release(5);

                return;
            }

            // #elon yoki #yangilik yo'q — bu post bizga tegishli emas.
            $update->forceFill(['processed_at' => now()])->save();

            return;
        }

        [$post, $newMedia] = DB::transaction(fn () => $this->store($parsed, $existing));

        foreach ($newMedia as $media) {
            DownloadPostMedia::dispatch($media->id);
        }

        // Albomning qolgan qismini kutamiz. ShouldBeUnique tufayli bir post
        // uchun faqat bitta tarjima vazifasi navbatga tushadi.
        TranslatePost::dispatch($post->id)
            ->delay(now()->addSeconds((int) config('telegram.media_group_wait_seconds')));

        $update->forceFill(['processed_at' => now()])->save();
    }

    /** @return array{0: Post, 1: array<int, PostMedia>} */
    private function store(ParsedPost $parsed, ?Post $post): array
    {
        // Tranzaksiya ichida qayta o'qiymiz: parallel ishchilar bir vaqtda
        // albomning turli bo'laklarini olib kelishi mumkin.
        $post = $this->findExisting($parsed) ?? $post;

        if ($post === null) {
            $post = Post::create([
                'chat_id' => $parsed->chatId,
                'message_id' => $parsed->messageId,
                'media_group_id' => $parsed->mediaGroupId,
                'type' => $parsed->type,
                'status' => PostStatus::Collecting,
                'source_text' => $parsed->text,
                'source_html' => $parsed->html,
                'source_locale' => (string) config('translation.source_locale'),
                'telegram_url' => $parsed->url,
                'posted_at' => $parsed->postedAt,
            ]);
        } elseif (trim($post->source_text) === '' && trim($parsed->text) !== '') {
            // Albomda matn ko'pincha keyingi bo'lakda keladi.
            $post->forceFill([
                'source_text' => $parsed->text,
                'source_html' => $parsed->html,
                'telegram_url' => $post->telegram_url ?? $parsed->url,
            ])->save();
        }

        $newMedia = [];
        $position = (int) $post->media()->max('position');

        foreach ($parsed->media as $item) {
            $exists = $post->media()->where('file_unique_id', $item['file_unique_id'])->exists();

            if ($exists) {
                continue;
            }

            $position++;

            $media = $post->media()->create([
                'file_id' => $item['file_id'],
                'file_unique_id' => $item['file_unique_id'],
                'kind' => $item['kind'],
                'mime' => $item['mime'],
                'size' => $item['size'],
                // Birinchi rasm muqova bo'ladi.
                'is_cover' => $item['kind'] === 'photo' && ! $post->media()->where('is_cover', true)->exists(),
                'position' => $position,
            ]);

            $newMedia[] = $media;
        }

        return [$post->refresh(), $newMedia];
    }

    private function findExisting(ParsedPost $parsed): ?Post
    {
        if ($parsed->mediaGroupId !== null) {
            $grouped = Post::where('chat_id', $parsed->chatId)
                ->where('media_group_id', $parsed->mediaGroupId)
                ->first();

            if ($grouped !== null) {
                return $grouped;
            }
        }

        return Post::where('chat_id', $parsed->chatId)
            ->where('message_id', $parsed->messageId)
            ->first();
    }

    private function fromWatchedChannel(array $message): bool
    {
        $expected = config('telegram.channel_id');

        if (blank($expected)) {
            return true;
        }

        $chat = $message['chat'] ?? [];
        $username = isset($chat['username']) ? '@'.ltrim((string) $chat['username'], '@') : null;

        return (string) ($chat['id'] ?? '') === (string) $expected
            || ($username !== null && strcasecmp($username, '@'.ltrim((string) $expected, '@')) === 0);
    }
}
