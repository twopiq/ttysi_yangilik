<?php

namespace App\Jobs;

use App\Models\PostMedia;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Telegramdagi faylni yuklab olib, lokal diskda saqlaydi. */
class DownloadPostMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60, 180];

    public function __construct(public readonly int $mediaId) {}

    public function handle(TelegramClient $telegram): void
    {
        $media = PostMedia::find($this->mediaId);

        if ($media === null || $media->isDownloaded()) {
            return;
        }

        $file = $telegram->downloadFile($media->file_id);

        $extension = pathinfo($file['path'], PATHINFO_EXTENSION) ?: 'jpg';
        $path = "telegram/{$media->post_id}/".Str::random(16).".{$extension}";

        Storage::disk('local')->put($path, $file['contents']);

        $media->forceFill([
            'path' => $path,
            'size' => $file['size'],
            'download_error' => null,
        ])->save();
    }

    public function failed(Throwable $exception): void
    {
        // Rasm tushmasa ham post tarjima qilinaveradi — moderator ko'radi.
        PostMedia::whereKey($this->mediaId)->update([
            'download_error' => mb_substr($exception->getMessage(), 0, 1000),
        ]);
    }
}
