<?php

namespace App\Jobs;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Services\Translation\Translator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Postni Claude orqali tarjima qilib, moderator ko'rigiga qo'yadi.
 *
 * ShouldBeUnique — albomning har bir bo'lagi uchun alohida tarjima
 * ketmasligi uchun: bitta post bo'yicha faqat bitta vazifa navbatda turadi.
 */
class TranslatePost implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $postId) {}

    public function uniqueId(): string
    {
        return (string) $this->postId;
    }

    public function uniqueFor(): int
    {
        // Kechikish oynasi + tarjimaning o'zi uchun zaxira vaqt.
        return (int) config('telegram.media_group_wait_seconds') + 600;
    }

    public function handle(Translator $translator): void
    {
        $post = Post::with('translations')->find($this->postId);

        if ($post === null) {
            return;
        }

        // Moderator allaqachon ko'rgan yoki chop etilgan postni qayta yozmaymiz.
        if (! in_array($post->status, [PostStatus::Collecting, PostStatus::Translating, PostStatus::Failed], true)) {
            return;
        }

        if (trim(strip_tags((string) $post->source_html)) === '') {
            $post->markFailed("Postda tarjima qilinadigan matn yo'q (faqat rasm).");

            return;
        }

        $post->forceFill(['status' => PostStatus::Translating])->save();

        $translations = $translator->translate(
            sourceHtml: $post->source_html,
            postType: $post->type->value,
            sourceLocale: $post->source_locale,
            targetLocales: (array) config('anthropic.target_locales'),
        );

        foreach ($translations as $locale => $translation) {
            $existing = $post->translations->firstWhere('locale', $locale);

            // Moderator qo'l bilan tahrirlagan matnni qayta tarjima bosib ketmaydi.
            if ($existing?->edited_by_human) {
                continue;
            }

            $post->translations()->updateOrCreate(
                ['locale' => $locale],
                [
                    'title' => mb_substr($translation['title'], 0, 250),
                    'summary' => mb_substr($translation['summary'], 0, 490),
                    'body' => $translation['body_html'],
                ],
            );
        }

        $post->forceFill([
            'status' => PostStatus::Review,
            'last_error' => null,
            'attempts' => $post->attempts + 1,
        ])->save();
    }

    public function failed(Throwable $exception): void
    {
        $post = Post::find($this->postId);

        $post?->markFailed($exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'Tarjima xatosi: '.$exception->getMessage());
    }
}
