<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramClient
{
    public function __construct(
        private readonly ?string $token = null,
        private readonly ?string $apiBase = null,
    ) {}

    private function token(): string
    {
        $token = $this->token ?? config('telegram.bot_token');

        if (blank($token)) {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN sozlanmagan.');
        }

        return $token;
    }

    private function base(): string
    {
        return rtrim($this->apiBase ?? config('telegram.api_base'), '/');
    }

    private function http(): PendingRequest
    {
        return Http::timeout(30)->retry(3, 500, throw: false);
    }

    /** Bot API metodini chaqiradi va `result` ni qaytaradi. */
    public function call(string $method, array $params = []): mixed
    {
        $response = $this->http()->asJson()->post(
            "{$this->base()}/bot{$this->token()}/{$method}",
            $params,
        );

        $body = $response->json();

        if (! is_array($body) || ($body['ok'] ?? false) !== true) {
            $description = is_array($body) ? ($body['description'] ?? 'nomalum xato') : $response->body();

            throw new RuntimeException("Telegram {$method} xatosi: {$description}");
        }

        return $body['result'];
    }

    public function setWebhook(string $url, string $secret): mixed
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            // Kanal postlari `channel_post`da keladi; tahrirlanganini ham kuzatamiz.
            'allowed_updates' => ['channel_post', 'edited_channel_post'],
            'drop_pending_updates' => false,
        ]);
    }

    public function deleteWebhook(): mixed
    {
        return $this->call('deleteWebhook');
    }

    public function getWebhookInfo(): mixed
    {
        return $this->call('getWebhookInfo');
    }

    public function getMe(): mixed
    {
        return $this->call('getMe');
    }

    /**
     * Faylni yuklab olib, xom baytlarini qaytaradi.
     *
     * @return array{contents: string, path: string, size: int}
     */
    public function downloadFile(string $fileId): array
    {
        $file = $this->call('getFile', ['file_id' => $fileId]);

        $remotePath = $file['file_path'] ?? null;
        if (! is_string($remotePath) || $remotePath === '') {
            throw new RuntimeException("getFile javobida file_path yo'q (file_id: {$fileId}).");
        }

        $size = (int) ($file['file_size'] ?? 0);
        $limit = (int) config('telegram.max_file_bytes');
        if ($size > 0 && $size > $limit) {
            throw new RuntimeException("Fayl juda katta: {$size} bayt (limit {$limit}).");
        }

        $response = $this->http()->get("{$this->base()}/file/bot{$this->token()}/{$remotePath}");

        if (! $response->successful()) {
            throw new RuntimeException("Faylni yuklab bo'lmadi (HTTP {$response->status()}).");
        }

        $contents = $response->body();

        return [
            'contents' => $contents,
            'path' => $remotePath,
            'size' => strlen($contents),
        ];
    }
}
