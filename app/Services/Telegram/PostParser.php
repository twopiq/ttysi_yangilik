<?php

namespace App\Services\Telegram;

use App\Enums\PostType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Telegram xabarini ichki ko'rinishga o'giradi.
 *
 * Telegram entity offsetlari UTF-16 kod birliklarida beriladi (baytlarda ham,
 * belgilarda ham emas), shuning uchun matn UTF-16LE ga o'girilib, ikki baytli
 * birliklar bo'yicha kesiladi.
 */
class PostParser
{
    /** Entity turi -> [ochuvchi teg, yopuvchi teg]. */
    private const INLINE_TAGS = [
        'bold' => ['<strong>', '</strong>'],
        'italic' => ['<em>', '</em>'],
        'underline' => ['<u>', '</u>'],
        'strikethrough' => ['<s>', '</s>'],
        'code' => ['<code>', '</code>'],
        'pre' => ['<pre><code>', '</code></pre>'],
        'blockquote' => ['<blockquote>', '</blockquote>'],
        'expandable_blockquote' => ['<blockquote>', '</blockquote>'],
        'spoiler' => ['<span class="spoiler">', '</span>'],
    ];

    public function parse(array $message): ParsedPost
    {
        $text = (string) ($message['text'] ?? $message['caption'] ?? '');
        $entities = $message['entities'] ?? $message['caption_entities'] ?? [];
        $entities = is_array($entities) ? $entities : [];

        [$type, $routingRanges] = $this->detectType($text, $entities);

        return new ParsedPost(
            chatId: (int) ($message['chat']['id'] ?? 0),
            messageId: (int) ($message['message_id'] ?? 0),
            mediaGroupId: isset($message['media_group_id']) ? (string) $message['media_group_id'] : null,
            type: $type,
            text: $this->plainText($text, $routingRanges),
            html: $this->toHtml($text, $entities, $routingRanges),
            media: $this->extractMedia($message),
            postedAt: isset($message['date']) ? Carbon::createFromTimestampUTC((int) $message['date']) : null,
            url: $this->permalink($message),
        );
    }

    /**
     * Post turini yo'naltiruvchi hashtaglardan aniqlaydi.
     *
     * @return array{0: ?PostType, 1: array<int, array{int, int}>} tur va matndan olib
     *                                                             tashlanadigan UTF-16 oraliqlar
     */
    private function detectType(string $text, array $entities): array
    {
        $map = (array) config('telegram.hashtag_types');
        $type = null;
        $ranges = [];

        foreach ($entities as $entity) {
            if (($entity['type'] ?? null) !== 'hashtag') {
                continue;
            }

            $offset = (int) ($entity['offset'] ?? 0);
            $length = (int) ($entity['length'] ?? 0);
            $tag = $this->slice($text, $offset, $length);
            $key = mb_strtolower(ltrim($tag, '#'));

            if (! isset($map[$key])) {
                continue;
            }

            // Birinchi tanilgan hashtag postning turini belgilaydi.
            $type ??= PostType::tryFrom($map[$key]);
            $ranges[] = [$offset, $length];
        }

        return [$type, $ranges];
    }

    /** Yo'naltiruvchi hashtaglarsiz sof matn. */
    private function plainText(string $text, array $routingRanges): string
    {
        $units = $this->units($text);
        $skip = $this->skipSet($routingRanges);

        $kept = [];
        foreach ($units as $i => $unit) {
            if (! isset($skip[$i])) {
                $kept[] = $unit;
            }
        }

        return $this->tidy($this->fromUnits($kept));
    }

    /** Entity'larni HTML tegga o'girib, paragraflarga bo'ladi. */
    private function toHtml(string $text, array $entities, array $routingRanges): string
    {
        $units = $this->units($text);
        $total = count($units);
        $skip = $this->skipSet($routingRanges);

        // Ochilish nuqtasi bo'yicha guruhlash; bir joyda bir nechta entity bo'lsa
        // uzunrog'i tashqarida turadi, shunda joylashuv to'g'ri bo'ladi.
        $starts = [];
        foreach ($entities as $entity) {
            $tags = $this->tagsFor($entity, $text);
            if ($tags === null) {
                continue;
            }

            $offset = (int) ($entity['offset'] ?? 0);
            $length = (int) ($entity['length'] ?? 0);
            if ($length <= 0 || $offset < 0 || $offset >= $total) {
                continue;
            }

            $starts[$offset][] = ['end' => min($offset + $length, $total), 'tags' => $tags];
        }

        foreach ($starts as &$group) {
            usort($group, fn (array $a, array $b) => $b['end'] <=> $a['end']);
        }
        unset($group);

        $out = '';
        $buffer = [];
        $stack = [];

        $flush = function () use (&$out, &$buffer): void {
            if ($buffer !== []) {
                $out .= e($this->fromUnits($buffer));
                $buffer = [];
            }
        };

        for ($i = 0; $i <= $total; $i++) {
            while ($stack !== [] && end($stack)['end'] === $i) {
                $flush();
                $frame = array_pop($stack);
                $out .= $frame['tags'][1];
            }

            if ($i === $total) {
                break;
            }

            foreach ($starts[$i] ?? [] as $entity) {
                $flush();
                $out .= $entity['tags'][0];
                $stack[] = $entity;
            }

            if (! isset($skip[$i])) {
                $buffer[] = $units[$i];
            }
        }

        $flush();

        while ($stack !== []) {
            $out .= array_pop($stack)['tags'][1];
        }

        return $this->paragraphs($out);
    }

    /** @return array{0: string, 1: string}|null */
    private function tagsFor(array $entity, string $text): ?array
    {
        $type = (string) ($entity['type'] ?? '');

        if (isset(self::INLINE_TAGS[$type])) {
            return self::INLINE_TAGS[$type];
        }

        if ($type === 'text_link') {
            $url = (string) ($entity['url'] ?? '');

            return $this->isSafeUrl($url)
                ? ['<a href="'.e($url).'" rel="noopener noreferrer" target="_blank">', '</a>']
                : null;
        }

        if ($type === 'url') {
            $url = $this->slice($text, (int) ($entity['offset'] ?? 0), (int) ($entity['length'] ?? 0));

            return $this->isSafeUrl($url)
                ? ['<a href="'.e($url).'" rel="noopener noreferrer" target="_blank">', '</a>']
                : null;
        }

        // hashtag, mention, bot_command va hokazo — oddiy matn bo'lib qoladi.
        return null;
    }

    private function isSafeUrl(string $url): bool
    {
        return Str::startsWith(mb_strtolower($url), ['http://', 'https://', 'tg://']);
    }

    /** Qatorlarni <p>/<br> ga o'giradi. */
    private function paragraphs(string $html): string
    {
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $chunks = preg_split('/\n{2,}/', $html) ?: [];

        $paragraphs = [];
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $paragraphs[] = '<p>'.str_replace("\n", "<br>\n", $chunk).'</p>';
        }

        return implode("\n", $paragraphs);
    }

    /** @return array<int, array{file_id: string, file_unique_id: string, kind: string, mime: ?string, size: ?int}> */
    private function extractMedia(array $message): array
    {
        $media = [];

        // `photo` — bir rasmning turli o'lchamlari; eng kattasi oxirida turadi.
        if (! empty($message['photo']) && is_array($message['photo'])) {
            $sizes = array_values($message['photo']);
            usort($sizes, fn ($a, $b) => ((int) ($a['file_size'] ?? 0)) <=> ((int) ($b['file_size'] ?? 0)));
            $largest = end($sizes);

            if (is_array($largest) && isset($largest['file_id'])) {
                $media[] = [
                    'file_id' => (string) $largest['file_id'],
                    'file_unique_id' => (string) ($largest['file_unique_id'] ?? $largest['file_id']),
                    'kind' => 'photo',
                    'mime' => 'image/jpeg',
                    'size' => isset($largest['file_size']) ? (int) $largest['file_size'] : null,
                ];
            }
        }

        if (! empty($message['document']) && is_array($message['document'])) {
            $document = $message['document'];
            $mime = isset($document['mime_type']) ? (string) $document['mime_type'] : null;

            $media[] = [
                'file_id' => (string) $document['file_id'],
                'file_unique_id' => (string) ($document['file_unique_id'] ?? $document['file_id']),
                'kind' => Str::startsWith((string) $mime, 'image/') ? 'photo' : 'document',
                'mime' => $mime,
                'size' => isset($document['file_size']) ? (int) $document['file_size'] : null,
            ];
        }

        return $media;
    }

    private function permalink(array $message): ?string
    {
        $username = $message['chat']['username'] ?? null;
        $messageId = $message['message_id'] ?? null;

        if (! is_string($username) || $username === '' || $messageId === null) {
            return null;
        }

        return "https://t.me/{$username}/{$messageId}";
    }

    /** @return array<int, string> UTF-16LE kod birliklari */
    private function units(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $converted = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

        return $converted === '' ? [] : str_split($converted, 2);
    }

    /** @param  array<int, string>  $units */
    private function fromUnits(array $units): string
    {
        if ($units === []) {
            return '';
        }

        return mb_convert_encoding(implode('', $units), 'UTF-8', 'UTF-16LE');
    }

    private function slice(string $text, int $offset, int $length): string
    {
        return $this->fromUnits(array_slice($this->units($text), $offset, $length));
    }

    /**
     * @param  array<int, array{int, int}>  $ranges
     * @return array<int, true>
     */
    private function skipSet(array $ranges): array
    {
        $skip = [];
        foreach ($ranges as [$offset, $length]) {
            for ($i = $offset; $i < $offset + $length; $i++) {
                $skip[$i] = true;
            }
        }

        return $skip;
    }

    /** Hashtag olib tashlangach qolgan ortiqcha bo'shliqlarni tozalaydi. */
    private function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
