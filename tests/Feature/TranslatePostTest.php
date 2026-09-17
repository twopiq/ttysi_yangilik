<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Jobs\TranslatePost;
use App\Models\Post;
use App\Services\Translation\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
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

    private function translator(array $result): Translator
    {
        return new class($result) extends Translator
        {
            public function __construct(private readonly array $result)
            {
                parent::__construct();
            }

            public function translate(string $sourceHtml, string $postType, string $sourceLocale, array $targetLocales): array
            {
                return $this->result;
            }
        };
    }

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

        (new TranslatePost($post->id))->handle($this->translator($this->sampleTranslations()));

        $post->refresh()->load('translations');

        $this->assertSame(PostStatus::Review, $post->status);
        $this->assertEqualsCanonicalizing(['uz', 'ru', 'en'], $post->translations->pluck('locale')->all());
        $this->assertSame('День открытых дверей', $post->translation('ru')->title);
        $this->assertSame('<p>Text</p>', $post->translation('en')->body);
    }

    /** Moderator qo'lda tuzatgan matnni qayta tarjima bosib ketmasligi kerak. */
    public function test_it_keeps_human_edited_translations(): void
    {
        $post = $this->makePost();
        $post->translations()->create([
            'locale' => 'ru',
            'title' => 'Qo\'lda yozilgan sarlavha',
            'summary' => '',
            'body' => '<p>Qo\'lda yozilgan matn</p>',
            'edited_by_human' => true,
        ]);

        (new TranslatePost($post->id))->handle($this->translator($this->sampleTranslations()));

        $this->assertSame("Qo'lda yozilgan sarlavha", $post->refresh()->translation('ru')->title);
        $this->assertSame('Open Day', $post->translation('en')->title);
    }

    public function test_it_does_not_touch_an_already_published_post(): void
    {
        $post = $this->makePost(['status' => PostStatus::Published]);

        (new TranslatePost($post->id))->handle($this->translator($this->sampleTranslations()));

        $this->assertSame(PostStatus::Published, $post->refresh()->status);
        $this->assertCount(0, $post->translations);
    }

    public function test_a_post_without_text_is_marked_failed(): void
    {
        $post = $this->makePost(['source_html' => '', 'source_text' => '']);

        (new TranslatePost($post->id))->handle($this->translator([]));

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertStringContainsString('matn', $post->last_error);
    }

    public function test_a_failing_translation_marks_the_post_failed(): void
    {
        $post = $this->makePost();

        (new TranslatePost($post->id))->failed(new RuntimeException('Claude javob bermadi'));

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertSame('Claude javob bermadi', $post->last_error);
    }
}
