<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ModerationTest extends TestCase
{
    use RefreshDatabase;

    private function reviewPost(): Post
    {
        $post = Post::create([
            'chat_id' => -1001234567890,
            'message_id' => 55,
            'type' => PostType::Announcement,
            'status' => PostStatus::Review,
            'source_text' => 'Imtihon jadvali',
            'source_html' => '<p>Imtihon jadvali</p>',
            'source_locale' => 'uz',
        ]);

        $post->translations()->createMany([
            ['locale' => 'uz', 'title' => 'Imtihon jadvali', 'summary' => '', 'body' => '<p>Matn</p>'],
            ['locale' => 'ru', 'title' => 'Расписание экзаменов', 'summary' => '', 'body' => '<p>Текст</p>'],
        ]);

        return $post->refresh();
    }

    public function test_the_panel_requires_a_login(): void
    {
        $this->get('/moderation')->assertRedirect('/login');
    }

    public function test_a_moderator_sees_the_queue(): void
    {
        $post = $this->reviewPost();

        $this->actingAs(User::factory()->create())
            ->get('/moderation')
            ->assertOk()
            ->assertSee('Imtihon jadvali');
    }

    public function test_saving_an_edit_marks_the_translation_as_human_edited(): void
    {
        $post = $this->reviewPost();

        $this->actingAs(User::factory()->create())
            ->put(route('moderation.update', $post), [
                'translations' => [
                    'uz' => ['title' => 'Yangi sarlavha', 'summary' => 'Qisqacha', 'body' => '<p>Tuzatildi</p>'],
                    'ru' => ['title' => 'Расписание экзаменов', 'summary' => '', 'body' => '<p>Текст</p>'],
                ],
            ])
            ->assertRedirect(route('moderation.edit', $post));

        $post->refresh()->load('translations');

        $this->assertSame('Yangi sarlavha', $post->translation('uz')->title);
        $this->assertTrue($post->translation('uz')->edited_by_human);
        // Tegilmagan tarjima qayta tarjimaga ochiq qoladi.
        $this->assertFalse($post->translation('ru')->edited_by_human);
    }

    public function test_approving_queues_the_publish_job(): void
    {
        Queue::fake();

        $post = $this->reviewPost();
        $moderator = User::factory()->create();

        $this->actingAs($moderator)
            ->post(route('moderation.approve', $post))
            ->assertRedirect(route('moderation.index'));

        $post->refresh();
        $this->assertSame(PostStatus::Approved, $post->status);
        $this->assertSame($moderator->id, $post->approved_by);
        $this->assertNotNull($post->approved_at);

        Queue::assertPushed(PublishPost::class);
    }

    public function test_a_post_without_translations_cannot_be_approved(): void
    {
        Queue::fake();

        $post = $this->reviewPost();
        $post->translations()->delete();

        $this->actingAs(User::factory()->create())
            ->from(route('moderation.edit', $post))
            ->post(route('moderation.approve', $post))
            ->assertRedirect(route('moderation.edit', $post))
            ->assertSessionHas('error');

        $this->assertSame(PostStatus::Review, $post->refresh()->status);
        Queue::assertNotPushed(PublishPost::class);
    }

    public function test_rejecting_keeps_the_post_out_of_the_queue(): void
    {
        Queue::fake();

        $post = $this->reviewPost();

        $this->actingAs(User::factory()->create())
            ->post(route('moderation.reject', $post))
            ->assertRedirect(route('moderation.index'));

        $this->assertSame(PostStatus::Rejected, $post->refresh()->status);
        Queue::assertNotPushed(PublishPost::class);
    }

    public function test_media_is_served_from_private_storage(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('telegram/1/a.jpg', 'RASM-BAYTLARI');

        $post = $this->reviewPost();
        $media = $post->media()->create([
            'file_id' => 'f1', 'file_unique_id' => 'u1', 'kind' => 'photo',
            'mime' => 'image/jpeg', 'path' => 'telegram/1/a.jpg', 'position' => 1,
        ]);

        // Marshrut `/moderation/{post}` bilan chalkashmasligi kerak.
        $this->actingAs(User::factory()->create())
            ->get(route('moderation.media', $media))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertSee('RASM-BAYTLARI');
    }

    public function test_media_requires_a_login(): void
    {
        $post = $this->reviewPost();
        $media = $post->media()->create([
            'file_id' => 'f1', 'file_unique_id' => 'u1', 'kind' => 'photo', 'position' => 1,
        ]);

        $this->get(route('moderation.media', $media))->assertRedirect('/login');
    }

    public function test_the_cover_image_can_be_changed(): void
    {
        $post = $this->reviewPost();

        $first = $post->media()->create(['file_id' => 'f1', 'file_unique_id' => 'u1', 'kind' => 'photo', 'is_cover' => true, 'position' => 1]);
        $second = $post->media()->create(['file_id' => 'f2', 'file_unique_id' => 'u2', 'kind' => 'photo', 'is_cover' => false, 'position' => 2]);

        $this->actingAs(User::factory()->create())
            ->put(route('moderation.update', $post), [
                'cover_media_id' => $second->id,
                'translations' => [
                    'uz' => ['title' => 'Imtihon jadvali', 'summary' => '', 'body' => '<p>Matn</p>'],
                ],
            ])
            ->assertRedirect(route('moderation.edit', $post));

        $this->assertFalse($first->refresh()->is_cover);
        $this->assertTrue($second->refresh()->is_cover);
    }
}
