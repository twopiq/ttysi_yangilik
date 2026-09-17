<?php

namespace Tests\Unit;

use App\Enums\PostType;
use App\Services\Telegram\PostParser;
use Tests\Support\TelegramFixtures;
use Tests\TestCase;

class PostParserTest extends TestCase
{
    private function parse(array $message)
    {
        return (new PostParser)->parse($message);
    }

    public function test_it_detects_news_hashtag_and_strips_it(): void
    {
        $parsed = $this->parse(TelegramFixtures::textPost()['channel_post']);

        $this->assertSame(PostType::News, $parsed->type);
        $this->assertStringNotContainsString('#yangilik', $parsed->text);
        $this->assertStringStartsWith('Ochiq eshiklar kuni', $parsed->text);
        $this->assertStringContainsString('<strong>Ochiq eshiklar kuni</strong>', $parsed->html);
    }

    public function test_it_detects_announcement_hashtag(): void
    {
        $parsed = $this->parse([
            'message_id' => 1,
            'chat' => ['id' => -100, 'type' => 'channel'],
            'text' => '#elon Imtihon jadvali',
            'entities' => [['type' => 'hashtag', 'offset' => 0, 'length' => 5]],
        ]);

        $this->assertSame(PostType::Announcement, $parsed->type);
        $this->assertSame('Imtihon jadvali', $parsed->text);
    }

    public function test_untagged_post_is_not_routable(): void
    {
        $parsed = $this->parse([
            'message_id' => 2,
            'chat' => ['id' => -100, 'type' => 'channel'],
            'text' => 'Oddiy post, hashtagsiz',
        ]);

        $this->assertNull($parsed->type);
        $this->assertFalse($parsed->isRoutable());
    }

    /**
     * Telegram offsetlari UTF-16 kod birliklarida. Emoji (surrogat juftlik) 2 ta,
     * kirill harfi 1 ta birlik egallaydi — bu yerda shu hisob tekshiriladi.
     */
    public function test_it_uses_utf16_offsets_for_entities(): void
    {
        // "#elon" = 0..4, "\n" = 5, "📣" = 6..7 (surrogat juftlik), " " = 8,
        // "Конференция" = 9..19 (11 ta kirill harfi).
        $text = "#elon\n📣 Конференция";

        $parsed = $this->parse([
            'message_id' => 3,
            'chat' => ['id' => -100, 'type' => 'channel'],
            'text' => $text,
            'entities' => [
                ['type' => 'hashtag', 'offset' => 0, 'length' => 5],
                ['type' => 'bold', 'offset' => 9, 'length' => 11],
            ],
        ]);

        $this->assertStringContainsString('<strong>Конференция</strong>', $parsed->html);
        $this->assertStringContainsString('📣', $parsed->html);
    }

    public function test_it_renders_links_and_escapes_html(): void
    {
        $text = '#yangilik Batafsil <b> & shartlar';

        $parsed = $this->parse([
            'message_id' => 4,
            'chat' => ['id' => -100, 'type' => 'channel'],
            'text' => $text,
            'entities' => [
                ['type' => 'hashtag', 'offset' => 0, 'length' => 9],
                ['type' => 'text_link', 'offset' => 10, 'length' => 8, 'url' => 'https://institut.uz/x'],
            ],
        ]);

        $this->assertStringContainsString('<a href="https://institut.uz/x"', $parsed->html);
        $this->assertStringContainsString('>Batafsil</a>', $parsed->html);
        // Matn ichidagi <b> teg emas, matn bo'lib qolishi kerak.
        $this->assertStringContainsString('&lt;b&gt;', $parsed->html);
        $this->assertStringContainsString('&amp;', $parsed->html);
    }

    public function test_it_rejects_javascript_links(): void
    {
        $parsed = $this->parse([
            'message_id' => 5,
            'chat' => ['id' => -100, 'type' => 'channel'],
            'text' => '#elon Bosing',
            'entities' => [
                ['type' => 'hashtag', 'offset' => 0, 'length' => 5],
                ['type' => 'text_link', 'offset' => 6, 'length' => 6, 'url' => 'javascript:alert(1)'],
            ],
        ]);

        $this->assertStringNotContainsString('javascript:', $parsed->html);
        $this->assertStringContainsString('Bosing', $parsed->html);
    }

    public function test_it_splits_paragraphs(): void
    {
        $parsed = $this->parse([
            'message_id' => 6,
            'chat' => ['id' => -100, 'type' => 'channel'],
            'text' => "#yangilik\nBirinchi xat\nIkkinchi xat\n\nYangi paragraf",
            'entities' => [['type' => 'hashtag', 'offset' => 0, 'length' => 9]],
        ]);

        $this->assertSame(2, substr_count($parsed->html, '<p>'));
        $this->assertStringContainsString('<br>', $parsed->html);
    }

    public function test_it_picks_the_largest_photo_size(): void
    {
        $message = TelegramFixtures::albumPart(1, 10, 'abc', '#elon Suratlar')['channel_post'];

        $parsed = $this->parse($message);

        $this->assertCount(1, $parsed->media);
        $this->assertSame('big-abc', $parsed->media[0]['file_id']);
        $this->assertSame('photo', $parsed->media[0]['kind']);
        $this->assertSame('13000000000000001', $parsed->mediaGroupId);
    }

    public function test_it_builds_a_permalink(): void
    {
        $parsed = $this->parse(TelegramFixtures::textPost()['channel_post']);

        $this->assertSame('https://t.me/institut_kanali/55', $parsed->url);
    }
}
