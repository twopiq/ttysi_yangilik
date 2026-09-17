<?php

namespace Tests\Feature;

use App\Contracts\TranslationDriver;
use App\Services\Translation\ClaudeTranslator;
use App\Services\Translation\GeminiTranslator;
use App\Services\Translation\ManualTranslator;
use App\Services\Translation\OpenAiCompatibleTranslator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class TranslationDriverTest extends TestCase
{
    private const SOURCE_HTML = '<p>Ochiq eshiklar kuni</p>';

    private const GEMINI_URL = 'generativelanguage.googleapis.com/*';

    /** @return array<string, array{title: string, summary: string, body_html: string}> */
    private function payload(): array
    {
        return [
            'uz' => ['title' => 'Ochiq eshiklar kuni', 'summary' => 'Uchrashuv', 'body_html' => '<p>Matn</p>'],
            'ru' => ['title' => 'День открытых дверей', 'summary' => 'Встреча', 'body_html' => '<p>Текст</p>'],
        ];
    }

    private function geminiResponse(string $text): array
    {
        return ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $text]]]]]];
    }

    public function test_the_configured_driver_is_the_one_that_gets_resolved(): void
    {
        $cases = [
            'gemini' => GeminiTranslator::class,
            'openai_compatible' => OpenAiCompatibleTranslator::class,
            'claude' => ClaudeTranslator::class,
            'manual' => ManualTranslator::class,
        ];

        foreach ($cases as $driver => $expected) {
            config(['translation.driver' => $driver]);

            $this->assertInstanceOf($expected, $this->app->make(TranslationDriver::class));
        }
    }

    public function test_an_unknown_driver_name_fails_loudly(): void
    {
        config(['translation.driver' => 'deepl']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/gemini/');

        $this->app->make(TranslationDriver::class);
    }

    public function test_gemini_sends_a_schema_it_accepts_and_returns_every_locale(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GEMINI_URL => Http::response($this->geminiResponse(json_encode($this->payload())))]);
        config(['translation.gemini.api_key' => 'test-gemini-key']);

        $result = $this->app->make(GeminiTranslator::class)
            ->translate(self::SOURCE_HTML, 'news', 'uz', ['ru']);

        $this->assertSame('День открытых дверей', $result['ru']['title']);
        $this->assertSame('<p>Matn</p>', $result['uz']['body_html']);

        Http::assertSent(function (Request $request) {
            $schema = $request->data()['generationConfig']['responseSchema'];

            // Gemini OpenAPI qism to'plamini qabul qiladi — additionalProperties uni buzadi.
            $this->assertArrayNotHasKey('additionalProperties', $schema);
            $this->assertArrayNotHasKey('additionalProperties', $schema['properties']['ru']);
            $this->assertSame(['uz', 'ru'], $schema['required']);

            return $request->hasHeader('x-goog-api-key', 'test-gemini-key');
        });
    }

    public function test_gemini_accepts_json_wrapped_in_a_markdown_code_fence(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GEMINI_URL => Http::response(
            $this->geminiResponse("```json\n".json_encode($this->payload())."\n```")
        )]);
        config(['translation.gemini.api_key' => 'test-gemini-key']);

        $result = $this->app->make(GeminiTranslator::class)
            ->translate(self::SOURCE_HTML, 'news', 'uz', ['ru']);

        $this->assertSame('День открытых дверей', $result['ru']['title']);
        $this->assertSame('<p>Текст</p>', $result['ru']['body_html']);
    }

    public function test_gemini_reports_a_spent_free_quota_clearly(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GEMINI_URL => Http::response(['error' => ['message' => 'Quota exceeded']], 429)]);
        config(['translation.gemini.api_key' => 'test-gemini-key']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/limiti tugadi/');

        $this->app->make(GeminiTranslator::class)->translate(self::SOURCE_HTML, 'news', 'uz', ['ru']);
    }

    public function test_gemini_without_a_key_does_not_call_the_network(): void
    {
        Http::preventStrayRequests();
        config(['translation.gemini.api_key' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/GEMINI_API_KEY/');

        $this->app->make(GeminiTranslator::class)->translate(self::SOURCE_HTML, 'news', 'uz', ['ru']);
    }

    public function test_an_openai_compatible_service_receives_the_token_and_the_schema(): void
    {
        Http::preventStrayRequests();
        Http::fake(['openrouter.ai/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($this->payload())]]],
        ])]);
        config([
            'translation.openai_compatible.base_url' => 'https://openrouter.ai/api/v1',
            'translation.openai_compatible.api_key' => 'test-router-key',
            'translation.openai_compatible.model' => 'free-model',
        ]);

        $result = $this->app->make(OpenAiCompatibleTranslator::class)
            ->translate(self::SOURCE_HTML, 'announcement', 'uz', ['ru']);

        $this->assertSame('Встреча', $result['ru']['summary']);

        Http::assertSent(function (Request $request) {
            $this->assertSame('free-model', $request->data()['model']);
            $this->assertSame('json_object', $request->data()['response_format']['type']);
            // json_object sxemani majburlamaydi, shuning uchun u promptga qo'shiladi.
            $this->assertStringContainsString('body_html', $request->data()['messages'][0]['content']);

            return $request->hasHeader('Authorization', 'Bearer test-router-key');
        });
    }

    public function test_an_incomplete_answer_is_rejected_instead_of_being_saved(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GEMINI_URL => Http::response(
            $this->geminiResponse(json_encode([
                'uz' => ['title' => 'Sarlavha', 'summary' => '', 'body_html' => '<p>Matn</p>'],
            ]))
        )]);
        config(['translation.gemini.api_key' => 'test-gemini-key']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/'ru' tili uchun/");

        $this->app->make(GeminiTranslator::class)->translate(self::SOURCE_HTML, 'news', 'uz', ['ru']);
    }

    public function test_the_manual_driver_makes_no_request_and_seeds_every_locale(): void
    {
        Http::preventStrayRequests();

        $result = $this->app->make(ManualTranslator::class)->translate(
            "<p>Imtihon jadvali</p>\n<p>Ikkinchi paragraf</p>",
            'announcement',
            'uz',
            ['ru', 'en'],
        );

        $this->assertSame(['uz', 'ru', 'en'], array_keys($result));
        $this->assertSame('Imtihon jadvali', $result['ru']['title']);
        $this->assertSame("<p>Imtihon jadvali</p>\n<p>Ikkinchi paragraf</p>", $result['en']['body_html']);
    }

    public function test_an_empty_post_is_refused_by_every_driver(): void
    {
        Http::preventStrayRequests();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/matn bo'sh/");

        $this->app->make(ManualTranslator::class)->translate('<p> </p>', 'news', 'uz', ['ru']);
    }
}
