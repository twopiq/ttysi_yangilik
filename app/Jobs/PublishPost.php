<?php

namespace App\Jobs;

use App\Contracts\SitePublisher;
use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Tasdiqlangan postni institut saytiga yuboradi. */
class PublishPost implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly int $postId) {}

    public function uniqueId(): string
    {
        return (string) $this->postId;
    }

    public function handle(SitePublisher $publisher): void
    {
        $post = Post::with(['translations', 'media'])->find($this->postId);

        if ($post === null || $post->status !== PostStatus::Approved) {
            return;
        }

        $result = $publisher->publish($post);

        $post->forceFill([
            'status' => PostStatus::Published,
            'remote_id' => $result['id'],
            'published_url' => $result['url'],
            'published_at' => now(),
            'last_error' => null,
        ])->save();
    }

    public function failed(Throwable $exception): void
    {
        Post::find($this->postId)?->markFailed('Nashr xatosi: '.$exception->getMessage());
    }
}
