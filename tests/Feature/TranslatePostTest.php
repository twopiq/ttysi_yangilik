<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Jobs\TranslatePost;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\FakeTranslationDriver;
use Tests\TestCase;

class TranslatePostTest extends TestCase
{
    use RefreshDatabase;

    private function makePost(array $overrides = []): Post
    {
        return Post::create(array_merge([
            'chat_id' => -1001234567890,
            'message_id' => 55,
            'type' => PostType::News,
            'status' => PostStatus::Collecting,
            'source_text' => 'Ochiq eshiklar kuni',
            'source_html' => '<p><strong>Ochiq eshiklar kuni</strong></p>',
            'source_locale' => 'uz',
        ], $overrides));
    }

    /** @return array<string, array{title: string, summary: string, body_html: string}> */
    private function sampleTranslations(): array
    {
        return [
            'uz' => ['title' => 'Ochiq eshiklar kuni', 'summary' => 'Uchrashuv', 'body_html' => '<p>Matn</p>'],
            'ru' => ['title' => 'День открытых дверей', 'summary' => 'Встреча', 'body_html' => '<p>Текст</p>'],
            'en' => ['title' => 'Open Day', 'summary' => 'Meeting', 'body_html' => '<p>Text</p>'],
        ];
    }

    public function test_it_stores_every_locale_and_moves_the_post_to_review(): void
    {
        $post = $this->makePost();

        (new TranslatePost($post->id))->handle(new FakeTranslationDriver($this->sampleTranslations()));

        $post->refresh()->load('translations');
        $this->assertSame(PostStatus::Review, $post->status);
        $this->assertEqualsCanonicalizing(['uz', 'ru', 'en'], $post->translations->pluck('locale')->all());
        $this->assertSame('День открытых дверей', $post->translation('ru')->title);
        $this->assertSame('<p>Text</p>', $post->translation('en')->body);
    }

    public function test_it_passes_the_source_html_and_configured_locales_to_the_driver(): void
    {
        config(['translation.target_locales' => ['ru', 'en']]);
        $post = $this->makePost();
        $driver = new FakeTranslationDriver($this->sampleTranslations());

        (new TranslatePost($post->id))->handle($driver);

        $this->assertSame('<p><strong>Ochiq eshiklar kuni</strong></p>', $driver->lastCall['sourceHtml']);
        $this->assertSame('news', $driver->lastCall['postType']);
        $this->assertSame('uz', $driver->lastCall['sourceLocale']);
        $this->assertSame(['ru', 'en'], $driver->lastCall['targetLocales']);
    }

    public function test_it_keeps_human_edited_translations(): void
    {
        $post = $this->makePost();
        $post->translations()->create([
            'locale' => 'ru',
            'title' => "Qo'lda yozilgan sarlavha",
            'summary' => '',
            'body' => "<p>Qo'lda yozilgan matn</p>",
            'edited_by_human' => true,
        ]);

        (new TranslatePost($post->id))->handle(new FakeTranslationDriver($this->sampleTranslations()));

        $post->refresh()->load('translations');
        $this->assertSame("Qo'lda yozilgan sarlavha", $post->translation('ru')->title);
        $this->assertSame('Open Day', $post->translation('en')->title);
    }

    public function test_it_does_not_touch_an_already_published_post(): void
    {
        $post = $this->makePost(['status' => PostStatus::Published]);
        $driver = new FakeTranslationDriver($this->sampleTranslations());

        (new TranslatePost($post->id))->handle($driver);

        $this->assertSame(PostStatus::Published, $post->refresh()->status);
        $this->assertCount(0, $post->translations);
        $this->assertNull($driver->lastCall);
    }

    public function test_a_post_without_text_is_marked_failed(): void
    {
        $post = $this->makePost(['source_html' => '', 'source_text' => '']);

        (new TranslatePost($post->id))->handle(new FakeTranslationDriver);

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertStringContainsString('matn', $post->last_error);
    }

    public function test_a_failing_translation_marks_the_post_failed(): void
    {
        $post = $this->makePost();

        (new TranslatePost($post->id))->failed(new RuntimeException('Servis javob bermadi'));

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertSame('Servis javob bermadi', $post->last_error);
    }
}
