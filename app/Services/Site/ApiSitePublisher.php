<?php

namespace App\Services\Site;

use App\Contracts\SitePublisher;
use App\Models\Post;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Postni saytning HTTP API'siga multipart so'rov bilan yuboradi.
 *
 * So'rov multipart/form-data ko'rinishida ketadi:
 *   external_id  — takroriy joylashuvni oldini olish uchun barqaror kalit
 *   type         — announcement | news
 *   section      — saytdagi bo'lim sluggi
 *   source_url   — kanaldagi asl post havolasi
 *   posted_at    — kanalda chiqqan vaqti (ISO-8601)
 *   translations — JSON: { "uz": {title, summary, body_html}, "ru": {...} }
 *   cover_index  — images[] ichidagi muqova rasmining tartib raqami
 *   images[]     — fayllar
 *
 * Sayt javobida { "id": ..., "url": ... } kutiladi.
 */
class ApiSitePublisher implements SitePublisher
{
    public function name(): string
    {
        return 'api';
    }

    /** @return array{id: ?string, url: ?string} */
    public function publish(Post $post): array
    {
        $endpoint = config('site.endpoint');

        if (blank($endpoint)) {
            throw new RuntimeException('SITE_API_ENDPOINT sozlanmagan.');
        }

        $post->loadMissing(['translations', 'media']);

        $translations = $post->translations
            ->mapWithKeys(fn ($translation) => [$translation->locale => [
                'title' => $translation->title,
                'summary' => $translation->summary,
                'body_html' => $translation->body,
            ]])
            ->all();

        if ($translations === []) {
            throw new RuntimeException("Postda birorta tarjima yo'q.");
        }

        $request = $this->request();
        $coverIndex = 0;
        $attached = 0;

        foreach ($post->media as $item) {
            $contents = $item->contents();

            if ($contents === null) {
                // Yuklab olinmagan fayl nashrni to'xtatmaydi, faqat tashlab ketiladi.
                continue;
            }

            if ($item->is_cover) {
                $coverIndex = $attached;
            }

            $request = $request->attach('images[]', $contents, $item->filename(), [
                'Content-Type' => $item->mime ?? 'application/octet-stream',
            ]);
            $attached++;
        }

        $response = $request->post($endpoint, [
            'external_id' => $this->externalId($post),
            'type' => $post->type->value,
            'section' => $post->type->section(),
            'source_url' => $post->telegram_url,
            'posted_at' => optional($post->posted_at)->toIso8601String(),
            'translations' => json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'cover_index' => (string) $coverIndex,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Sayt API xatosi (HTTP {$response->status()}): ".mb_substr($response->body(), 0, 500)
            );
        }

        $body = $response->json();

        return [
            'id' => isset($body['id']) ? (string) $body['id'] : null,
            'url' => isset($body['url']) ? (string) $body['url'] : null,
        ];
    }

    public function externalId(Post $post): string
    {
        return "tg:{$post->chat_id}:{$post->message_id}";
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout((int) config('site.timeout'))
            ->acceptJson()
            ->asMultipart();

        $token = config('site.token');

        return blank($token) ? $request : $request->withToken($token);
    }
}
