<?php

namespace Tests\Feature;

use App\Contracts\SitePublisher;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Jobs\PublishPost;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublishPostTest extends TestCase
{
    use RefreshDatabase;

    private function approvedPost(): Post
    {
        $post = Post::create([
            'chat_id' => -1001234567890,
            'message_id' => 55,
            'type' => PostType::News,
            'status' => PostStatus::Approved,
            'source_text' => 'Ochiq eshiklar kuni',
            'source_html' => '<p>Ochiq eshiklar kuni</p>',
            'source_locale' => 'uz',
            'telegram_url' => 'https://t.me/institut_kanali/55',
            'posted_at' => now(),
        ]);

        $post->translations()->createMany([
            ['locale' => 'uz', 'title' => 'Ochiq eshiklar kuni', 'summary' => 'Uchrashuv', 'body' => '<p>Matn</p>'],
            ['locale' => 'ru', 'title' => 'День открытых дверей', 'summary' => 'Встреча', 'body' => '<p>Текст</p>'],
        ]);

        return $post->refresh();
    }

    public function test_it_sends_translations_and_marks_the_post_published(): void
    {
        Http::fake([
            'institut.test/*' => Http::response(['id' => 'news-42', 'url' => 'https://institut.test/yangiliklar/42']),
        ]);

        $post = $this->approvedPost();

        (new PublishPost($post->id))->handle(app(SitePublisher::class));

        Http::assertSent(function (Request $request) {
            $fields = collect($request->data())->keyBy('name');

            $this->assertSame('tg:-1001234567890:55', $fields['external_id']['contents']);
            $this->assertSame('news', $fields['type']['contents']);
            $this->assertSame('yangiliklar', $fields['section']['contents']);

            $translations = json_decode($fields['translations']['contents'], true);
            $this->assertSame('День открытых дверей', $translations['ru']['title']);
            $this->assertSame('<p>Matn</p>', $translations['uz']['body_html']);

            return $request->hasHeader('Authorization', 'Bearer test-site-token');
        });

        $post->refresh();
        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertSame('news-42', $post->remote_id);
        $this->assertSame('https://institut.test/yangiliklar/42', $post->published_url);
    }

    public function test_it_attaches_downloaded_images_with_the_cover_first(): void
    {
        Storage::fake('local');
        Http::fake(['institut.test/*' => Http::response(['id' => '1', 'url' => 'https://institut.test/x'])]);

        $post = $this->approvedPost();

        Storage::disk('local')->put('telegram/1/a.jpg', 'AAA');
        Storage::disk('local')->put('telegram/1/b.jpg', 'BBB');

        $post->media()->createMany([
            ['file_id' => 'f1', 'file_unique_id' => 'u1', 'kind' => 'photo', 'mime' => 'image/jpeg',
                'path' => 'telegram/1/a.jpg', 'is_cover' => true, 'position' => 1],
            ['file_id' => 'f2', 'file_unique_id' => 'u2', 'kind' => 'photo', 'mime' => 'image/jpeg',
                'path' => 'telegram/1/b.jpg', 'is_cover' => false, 'position' => 2],
        ]);

        (new PublishPost($post->id))->handle(app(SitePublisher::class));

        Http::assertSent(function (Request $request) {
            $images = collect($request->data())->where('name', 'images[]');
            $this->assertCount(2, $images);

            $coverIndex = collect($request->data())->firstWhere('name', 'cover_index');
            $this->assertSame('0', $coverIndex['contents']);

            return true;
        });
    }

    /** Yuklab olinmagan rasm nashrni to'xtatmasligi kerak. */
    public function test_media_that_failed_to_download_is_skipped(): void
    {
        Storage::fake('local');
        Http::fake(['institut.test/*' => Http::response(['id' => '1', 'url' => 'https://institut.test/x'])]);

        $post = $this->approvedPost();
        $post->media()->create([
            'file_id' => 'f1', 'file_unique_id' => 'u1', 'kind' => 'photo',
            'path' => null, 'download_error' => 'timeout', 'position' => 1,
        ]);

        (new PublishPost($post->id))->handle(app(SitePublisher::class));

        $this->assertSame(PostStatus::Published, $post->refresh()->status);
    }

    public function test_a_site_error_marks_the_post_failed(): void
    {
        Http::fake(['institut.test/*' => Http::response('Server xatosi', 500)]);

        $post = $this->approvedPost();
        $job = new PublishPost($post->id);

        try {
            $job->handle(app(SitePublisher::class));
            $this->fail('Xato kutilgan edi.');
        } catch (\RuntimeException $e) {
            $job->failed($e);
        }

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertStringContainsString('HTTP 500', $post->last_error);
    }

    public function test_a_post_that_is_not_approved_is_not_published(): void
    {
        Http::fake();

        $post = $this->approvedPost();
        $post->forceFill(['status' => PostStatus::Review])->save();

        (new PublishPost($post->id))->handle(app(SitePublisher::class));

        Http::assertNothingSent();
    }
}
