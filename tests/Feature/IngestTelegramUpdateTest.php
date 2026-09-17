<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Jobs\DownloadPostMedia;
use App\Jobs\IngestTelegramUpdate;
use App\Jobs\TranslatePost;
use App\Models\Post;
use App\Models\TelegramUpdate;
use App\Services\Telegram\PostParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TelegramFixtures;
use Tests\TestCase;

class IngestTelegramUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function ingest(array $payload): void
    {
        $update = TelegramUpdate::create([
            'update_id' => $payload['update_id'],
            'payload' => $payload,
        ]);

        (new IngestTelegramUpdate($update->id))->handle(app(PostParser::class));
    }

    public function test_it_creates_a_post_and_queues_translation(): void
    {
        Queue::fake();

        $this->ingest(TelegramFixtures::textPost());

        $post = Post::sole();
        $this->assertSame(PostType::News, $post->type);
        $this->assertSame(PostStatus::Collecting, $post->status);
        $this->assertStringNotContainsString('#yangilik', $post->source_text);

        Queue::assertPushed(TranslatePost::class);
    }

    public function test_it_skips_posts_without_a_routing_hashtag(): void
    {
        Queue::fake();

        $payload = TelegramFixtures::textPost();
        $payload['channel_post']['text'] = 'Hashtagsiz post';
        $payload['channel_post']['entities'] = [];

        $this->ingest($payload);

        $this->assertDatabaseCount('posts', 0);
        Queue::assertNotPushed(TranslatePost::class);
        $this->assertNotNull(TelegramUpdate::sole()->processed_at);
    }

    public function test_it_skips_posts_from_another_channel(): void
    {
        Queue::fake();

        $payload = TelegramFixtures::textPost();
        $payload['channel_post']['chat']['id'] = -1009999999999;
        $payload['channel_post']['chat']['username'] = 'boshqa_kanal';

        $this->ingest($payload);

        $this->assertDatabaseCount('posts', 0);
    }

    /**
     * Albom bir nechta update bo'lib keladi va sarlavha faqat bittasida bo'ladi.
     * Hammasi bitta Postga yig'ilishi kerak.
     */
    public function test_album_parts_collapse_into_one_post(): void
    {
        Queue::fake();

        $this->ingest(TelegramFixtures::albumPart(1, 10, 'a', '#elon Tadbir suratlari'));
        $this->ingest(TelegramFixtures::albumPart(2, 11, 'b'));
        $this->ingest(TelegramFixtures::albumPart(3, 12, 'c'));

        $post = Post::sole();
        $this->assertSame(PostType::Announcement, $post->type);
        $this->assertSame('Tadbir suratlari', $post->source_text);
        $this->assertCount(3, $post->media);

        // Faqat birinchi rasm muqova.
        $this->assertSame(1, $post->media->where('is_cover', true)->count());
        $this->assertSame('bu-a', $post->media->firstWhere('is_cover', true)->file_unique_id);

        Queue::assertPushed(DownloadPostMedia::class, 3);
    }

    /** Matn albomning keyingi bo'lagida kelsa ham qo'lga olinishi kerak. */
    public function test_caption_arriving_in_a_later_part_is_captured(): void
    {
        Queue::fake();

        $this->ingest(TelegramFixtures::albumPart(1, 10, 'a', '#yangilik'));
        $this->ingest(TelegramFixtures::albumPart(2, 11, 'b', '#yangilik Sport musobaqasi'));

        $this->assertSame('Sport musobaqasi', Post::sole()->source_text);
    }

    public function test_an_already_processed_update_is_not_reprocessed(): void
    {
        Queue::fake();

        $payload = TelegramFixtures::textPost();
        $update = TelegramUpdate::create([
            'update_id' => $payload['update_id'],
            'payload' => $payload,
            'processed_at' => now(),
        ]);

        (new IngestTelegramUpdate($update->id))->handle(app(PostParser::class));

        $this->assertDatabaseCount('posts', 0);
    }
}
