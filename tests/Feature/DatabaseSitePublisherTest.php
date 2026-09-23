<?php

namespace Tests\Feature;

use App\Contracts\SitePublisher;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Models\Post;
use App\Services\Site\DatabaseSitePublisher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DatabaseSitePublisherTest extends TestCase
{
    use RefreshDatabase;

    private string $siteDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siteDatabase = tempnam(sys_get_temp_dir(), 'site').'.sqlite';
        touch($this->siteDatabase);

        config([
            'database.connections.site' => ['driver' => 'sqlite', 'database' => $this->siteDatabase, 'prefix' => ''],
            'site.driver' => 'database',
            'site.database.url_template' => 'https://institut.uz/yangiliklar/{slug}',
        ]);

        $this->makeSiteSchema();
    }

    protected function tearDown(): void
    {
        DB::purge('site');
        @unlink($this->siteDatabase);

        parent::tearDown();
    }

    private function makeSiteSchema(): void
    {
        $schema = Schema::connection('site');

        $schema->create('news', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('image')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->text('body')->nullable();
            $table->string('title_uz')->nullable();
            $table->string('title_ru')->nullable();
            $table->text('body_uz')->nullable();
            $table->text('body_ru')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        $schema->create('news_translations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('news_id');
            $table->string('locale', 8);
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('body');
            $table->unique(['news_id', 'locale']);
        });

        $schema->create('news_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('news_id');
            $table->string('path');
            $table->integer('sort_order')->default(0);
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
            'posted_at' => '2026-09-20 10:00:00',
        ]);

        $post->translations()->createMany([
            ['locale' => 'uz', 'title' => 'Ochiq eshiklar kuni', 'summary' => 'Uchrashuv', 'body' => '<p>Matn</p>'],
            ['locale' => 'ru', 'title' => 'День открытых дверей', 'summary' => 'Встреча', 'body' => '<p>Текст</p>'],
        ]);

        return $post->refresh();
    }

    private function publisher(): DatabaseSitePublisher
    {
        return $this->app->make(DatabaseSitePublisher::class);
    }

    public function test_the_configured_driver_resolves_to_the_database_publisher(): void
    {
        $this->assertInstanceOf(DatabaseSitePublisher::class, $this->app->make(SitePublisher::class));
    }

    public function test_it_writes_the_main_row_and_one_row_per_locale(): void
    {
        $post = $this->approvedPost();

        $result = $this->publisher()->publish($post);

        $this->assertSame('https://institut.uz/yangiliklar/ochiq-eshiklar-kuni', $result['url']);

        $news = DB::connection('site')->table('news')->sole();
        $this->assertSame('ochiq-eshiklar-kuni', $news->slug);
        $this->assertSame('2026-09-20 10:00:00', $news->published_at);

        $translations = DB::connection('site')->table('news_translations')
            ->orderBy('locale')->get()->keyBy('locale');
        $this->assertSame(['ru', 'uz'], $translations->keys()->sort()->values()->all());
        $this->assertSame('День открытых дверей', $translations['ru']->title);
        $this->assertSame('Uchrashuv', $translations['uz']->description);
        $this->assertSame('<p>Текст</p>', $translations['ru']->body);
    }

    public function test_republishing_updates_the_same_row_instead_of_adding_another(): void
    {
        $post = $this->approvedPost();

        $first = $this->publisher()->publish($post);
        $post->forceFill(['remote_id' => $first['id']])->save();
        $post->translation('uz')->forceFill(['title' => 'Tuzatilgan sarlavha'])->save();

        $this->publisher()->publish($post->refresh());

        $this->assertSame(1, DB::connection('site')->table('news')->count());
        $this->assertSame(2, DB::connection('site')->table('news_translations')->count());
        $this->assertSame(
            'Tuzatilgan sarlavha',
            DB::connection('site')->table('news_translations')->where('locale', 'uz')->value('title'),
        );
    }

    public function test_a_taken_slug_gets_a_numeric_suffix(): void
    {
        DB::connection('site')->table('news')->insert(['slug' => 'ochiq-eshiklar-kuni']);

        $this->publisher()->publish($this->approvedPost());

        $this->assertNotNull(
            DB::connection('site')->table('news')->where('slug', 'ochiq-eshiklar-kuni-2')->first(),
        );
    }

    public function test_the_defaults_from_the_config_land_on_every_row(): void
    {
        config([
            'site.database.defaults.common' => ['is_published' => true],
            'site.database.defaults.news' => ['category_id' => 7],
        ]);

        $this->publisher()->publish($this->approvedPost());

        $news = DB::connection('site')->table('news')->sole();
        $this->assertSame(7, (int) $news->category_id);
        $this->assertSame(1, (int) $news->is_published);
    }

    public function test_the_json_layout_writes_one_column_per_field(): void
    {
        config(['site.database.layout' => 'json']);

        $this->publisher()->publish($this->approvedPost());

        $news = DB::connection('site')->table('news')->sole();
        $title = json_decode($news->title, true);
        $this->assertSame('Ochiq eshiklar kuni', $title['uz']);
        $this->assertSame('День открытых дверей', $title['ru']);
        $this->assertSame('<p>Текст</p>', json_decode($news->body, true)['ru']);
        $this->assertSame(0, DB::connection('site')->table('news_translations')->count());
    }

    public function test_the_suffixed_layout_writes_one_column_per_locale(): void
    {
        config([
            'site.database.layout' => 'columns',
            'site.database.suffixed' => ['title' => 'title_{locale}', 'summary' => null, 'body' => 'body_{locale}'],
        ]);

        $this->publisher()->publish($this->approvedPost());

        $news = DB::connection('site')->table('news')->sole();
        $this->assertSame('Ochiq eshiklar kuni', $news->title_uz);
        $this->assertSame('День открытых дверей', $news->title_ru);
        $this->assertSame('<p>Текст</p>', $news->body_ru);
    }

    public function test_it_copies_images_to_the_site_storage_with_the_cover_first(): void
    {
        Storage::fake('local');
        $siteStorage = sys_get_temp_dir().'/site-storage-'.uniqid();
        config([
            'site.database.storage.path' => $siteStorage,
            'site.database.storage.subdirectory' => 'news',
            'site.database.storage.stored_prefix' => 'news',
        ]);

        $post = $this->approvedPost();
        Storage::disk('local')->put('telegram/1/second.jpg', 'IKKINCHI');
        Storage::disk('local')->put('telegram/1/cover.jpg', 'MUQOVA');
        $post->media()->createMany([
            ['file_id' => 'f1', 'file_unique_id' => 'u1', 'kind' => 'photo', 'mime' => 'image/jpeg',
                'path' => 'telegram/1/second.jpg', 'is_cover' => false, 'position' => 1],
            ['file_id' => 'f2', 'file_unique_id' => 'u2', 'kind' => 'photo', 'mime' => 'image/jpeg',
                'path' => 'telegram/1/cover.jpg', 'is_cover' => true, 'position' => 2],
        ]);

        $this->publisher()->publish($post->refresh());

        $this->assertSame('MUQOVA', file_get_contents("{$siteStorage}/news/cover.jpg"));
        $this->assertSame('IKKINCHI', file_get_contents("{$siteStorage}/news/second.jpg"));
        $this->assertSame('news/cover.jpg', DB::connection('site')->table('news')->value('image'));

        $images = DB::connection('site')->table('news_images')->orderBy('sort_order')->pluck('path');
        $this->assertSame(['news/cover.jpg', 'news/second.jpg'], $images->all());

        array_map('unlink', glob("{$siteStorage}/news/*"));
    }

    public function test_without_a_storage_path_the_text_is_still_published(): void
    {
        Storage::fake('local');
        config(['site.database.storage.path' => null]);

        $post = $this->approvedPost();
        Storage::disk('local')->put('telegram/1/a.jpg', 'RASM');
        $post->media()->create([
            'file_id' => 'f1', 'file_unique_id' => 'u1', 'kind' => 'photo',
            'path' => 'telegram/1/a.jpg', 'is_cover' => true, 'position' => 1,
        ]);

        $this->publisher()->publish($post->refresh());

        $this->assertSame(1, DB::connection('site')->table('news')->count());
        $this->assertNull(DB::connection('site')->table('news')->value('image'));
        $this->assertSame(0, DB::connection('site')->table('news_images')->count());
    }

    public function test_a_post_without_translations_is_refused(): void
    {
        $post = $this->approvedPost();
        $post->translations()->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches("/tarjima yo'q/");

        $this->publisher()->publish($post->refresh());
    }
}
