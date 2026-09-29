<?php

namespace Tests\Feature;

use App\Contracts\SitePublisher;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Models\Post;
use App\Services\Site\AdminSitePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class AdminSitePublisherTest extends TestCase
{
    use RefreshDatabase;

    private const PANEL = 'https://panel.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.driver' => 'admin',
            'site.admin.base_url' => self::PANEL,
            'site.admin.login_path' => '/login',
            'site.admin.create_path' => '/news/create',
            'site.admin.username' => 'panel_admin',
            'site.admin.password' => 'maxfiy',
            'site.admin.login_fields' => ['username' => 'login', 'password' => 'password'],
            'site.admin.fields' => [
                'title' => 'title[{locale}]',
                'summary' => 'anons[{locale}]',
                'body' => 'content[{locale}]',
            ],
            'site.admin.image_field' => 'rasm',
            'site.admin.gallery_field' => 'gallereya[]',
            'site.admin.extra' => ['common' => ['is_active' => 1], 'news' => ['category_id' => 3]],
            'site.admin.url_template' => null,
        ]);
    }

    private function loginPage(): string
    {
        return '<form method="post" action="/login">'
            .'<input type="hidden" name="_token" value="login-token">'
            .'<input type="text" name="login"><input type="password" name="password"></form>';
    }

    private function createPage(): string
    {
        $locales = '';

        foreach (['uz', 'ru', 'en'] as $locale) {
            $locales .= "<input type=\"text\" name=\"title[{$locale}]\" required>"
                ."<input type=\"text\" name=\"anons[{$locale}]\">"
                ."<textarea name=\"content[{$locale}]\" required></textarea>";
        }

        return '<a href="/logout">Chiqish</a>'
            .'<form method="post" action="/news" enctype="multipart/form-data">'
            .'<input type="hidden" name="_token" value="form-token">'
            .'<input type="hidden" name="author_id" value="7">'
            .$locales
            .'<input type="file" name="rasm"><input type="file" name="gallereya[]">'
            .'<button type="submit" name="save">Saqlash</button></form>';
    }

    /** @param  callable(Request): mixed|null  $onSubmit */
    private function fakePanel(?callable $onSubmit = null): void
    {
        Http::preventStrayRequests();

        Http::fake(function (Request $request) use ($onSubmit) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/login' && $request->method() === 'GET' => Http::response($this->loginPage()),
                $path === '/login' => Http::response('<a href="/logout">Chiqish</a><h1>Boshqaruv</h1>'),
                $path === '/news/create' => Http::response($this->createPage()),
                $path === '/news' => $onSubmit
                    ? $onSubmit($request)
                    : Http::response('<a href="/logout">Chiqish</a><h1>Saqlandi</h1>'),
                default => Http::response('Topilmadi', 404),
            };
        });
    }

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
        ]);

        $post->translations()->createMany([
            ['locale' => 'uz', 'title' => 'Ochiq eshiklar kuni', 'summary' => 'Uchrashuv', 'body' => '<p>Matn</p>'],
            ['locale' => 'ru', 'title' => 'День открытых дверей', 'summary' => 'Встреча', 'body' => '<p>Текст</p>'],
            ['locale' => 'en', 'title' => 'Open Day', 'summary' => 'Meeting', 'body' => '<p>Text</p>'],
        ]);

        return $post->refresh();
    }

    private function publisher(): AdminSitePublisher
    {
        return $this->app->make(AdminSitePublisher::class);
    }

    /**
     * Rasmsiz post form-encoded, rasmli post multipart ketadi — ikkisidan
     * ham matn qiymatlarini bir ko'rinishga keltiradi.
     *
     * @return array<string, string>
     */
    private function textValuesSent(Request $request): array
    {
        $data = $request->data();

        if (! array_is_list($data)) {
            return $data;
        }

        $values = [];

        foreach ($data as $part) {
            if (! isset($part['filename'])) {
                $values[$part['name']] = $part['contents'];
            }
        }

        return $values;
    }

    public function test_the_configured_driver_resolves_to_the_admin_publisher(): void
    {
        $this->assertInstanceOf(AdminSitePublisher::class, $this->app->make(SitePublisher::class));
    }

    public function test_it_logs_in_with_the_form_token_and_the_configured_field_names(): void
    {
        $this->fakePanel();

        $this->publisher()->publish($this->approvedPost());

        Http::assertSent(function (Request $request) {
            if (parse_url($request->url(), PHP_URL_PATH) !== '/login' || $request->method() !== 'POST') {
                return false;
            }

            $this->assertSame('panel_admin', $request->data()['login']);
            $this->assertSame('maxfiy', $request->data()['password']);
            $this->assertSame('login-token', $request->data()['_token']);

            return true;
        });
    }

    public function test_it_fills_every_locale_and_carries_the_forms_hidden_fields(): void
    {
        $captured = null;
        $this->fakePanel(function (Request $request) use (&$captured) {
            $captured = $request;

            return Http::response('<a href="/logout">Chiqish</a>Saqlandi');
        });

        $this->publisher()->publish($this->approvedPost());

        $values = $this->textValuesSent($captured);

        $this->assertSame('Ochiq eshiklar kuni', $values['title[uz]']);
        $this->assertSame('День открытых дверей', $values['title[ru]']);
        $this->assertSame('Open Day', $values['title[en]']);
        $this->assertSame('Встреча', $values['anons[ru]']);
        $this->assertSame('<p>Text</p>', $values['content[en]']);

        // Formadan kelgan yashirin maydonlar o'zgarmagan qaytishi kerak.
        $this->assertSame('form-token', $values['_token']);
        $this->assertSame('7', $values['author_id']);

        // Sozlamadagi doimiy qiymatlar. Simda ikkisi ham satr bo'lib ketadi,
        // shuning uchun transportga bog'liq bo'lmagan solishtirish.
        $this->assertSame('3', (string) $values['category_id']);
        $this->assertSame('1', (string) $values['is_active']);

        // Tugma avtomatik yuborilmaydi.
        $this->assertArrayNotHasKey('save', $values);
    }

    public function test_the_cover_goes_to_the_image_field_and_the_rest_to_the_gallery(): void
    {
        Storage::fake('local');
        $captured = null;
        $this->fakePanel(function (Request $request) use (&$captured) {
            $captured = $request;

            return Http::response('<a href="/logout">Chiqish</a>Saqlandi');
        });

        $post = $this->approvedPost();
        Storage::disk('local')->put('telegram/1/extra.jpg', 'QOSHIMCHA');
        Storage::disk('local')->put('telegram/1/cover.jpg', 'MUQOVA');
        $post->media()->createMany([
            ['file_id' => 'f1', 'file_unique_id' => 'u1', 'kind' => 'photo', 'mime' => 'image/jpeg',
                'path' => 'telegram/1/extra.jpg', 'is_cover' => false, 'position' => 1],
            ['file_id' => 'f2', 'file_unique_id' => 'u2', 'kind' => 'photo', 'mime' => 'image/jpeg',
                'path' => 'telegram/1/cover.jpg', 'is_cover' => true, 'position' => 2],
        ]);

        $this->publisher()->publish($post->refresh());

        $files = [];
        foreach ($captured->data() as $part) {
            if (isset($part['filename'])) {
                $files[] = [$part['name'], $part['filename'], $part['contents']];
            }
        }

        $this->assertSame([
            ['rasm', 'cover.jpg', 'MUQOVA'],
            ['gallereya[]', 'extra.jpg', 'QOSHIMCHA'],
        ], $files);
    }

    /** Bir tilli panel: shablonda {locale} bo'lmasa faqat manba tili yuboriladi. */
    public function test_a_single_language_panel_receives_only_the_source_locale(): void
    {
        $captured = null;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$captured) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/login' && $request->method() === 'GET') {
                return Http::response($this->loginPage());
            }

            if ($path === '/login') {
                return Http::response('<a href="/logout">Chiqish</a>');
            }

            if ($path === '/news/create') {
                return Http::response('<a href="/logout">Chiqish</a>'
                    .'<form method="post" action="/news"><input type="hidden" name="_token" value="t">'
                    .'<input type="text" name="title"><textarea name="body"></textarea></form>');
            }

            $captured = $request;

            return Http::response('<a href="/logout">Chiqish</a>Saqlandi');
        });

        config([
            'site.admin.fields' => ['title' => 'title', 'summary' => null, 'body' => 'body'],
            'site.admin.image_field' => null,
            'site.admin.gallery_field' => null,
            'site.admin.extra' => [],
        ]);

        $this->publisher()->publish($this->approvedPost());

        $this->assertSame('Ochiq eshiklar kuni', $captured->data()['title']);
        $this->assertSame('<p>Matn</p>', $captured->data()['body']);
        $this->assertArrayNotHasKey('title[ru]', $captured->data());
    }

    public function test_a_field_name_that_is_not_on_the_form_fails_with_the_real_field_list(): void
    {
        $this->fakePanel();
        config(['site.admin.fields.title' => 'sarlavha_{locale}']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sarlavha_(uz|ru|en).*title\[uz\]/s');

        $this->publisher()->publish($this->approvedPost());
    }

    public function test_bad_credentials_are_reported_instead_of_silently_posting(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return $path === '/login'
                ? Http::response($this->loginPage())
                : Http::response('Topilmadi', 404);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/login yoki parol qabul qilinmadi/');

        $this->publisher()->publish($this->approvedPost());
    }

    /** Sayt validatsiya xatosi bersa, sababi moderatorga ko'rinishi kerak. */
    public function test_a_validation_error_from_the_site_is_surfaced(): void
    {
        $this->fakePanel(fn () => Http::response(
            '<ul><li class="invalid-feedback">category_id tanlanmagan</li></ul>',
            422,
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/category_id tanlanmagan/');

        $this->publisher()->publish($this->approvedPost());
    }

    public function test_a_post_without_translations_is_refused_before_any_request(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $post = $this->approvedPost();
        $post->translations()->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/tarjima yo'q/");

        $this->publisher()->publish($post->refresh());
    }
}
