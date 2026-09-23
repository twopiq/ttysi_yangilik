<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VerifySiteMappingTest extends TestCase
{
    private string $siteDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siteDatabase = tempnam(sys_get_temp_dir(), 'verify').'.sqlite';
        touch($this->siteDatabase);

        config([
            'database.connections.site' => ['driver' => 'sqlite', 'database' => $this->siteDatabase, 'prefix' => ''],
            'site.driver' => 'database',
            'site.database.storage.path' => null,
            'site.database.media.table' => null,
        ]);

        Schema::connection('site')->create('news', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->string('image')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('site')->create('news_translations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('news_id');
            $table->string('locale', 8);
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('body');
        });
    }

    protected function tearDown(): void
    {
        DB::purge('site');
        @unlink($this->siteDatabase);

        parent::tearDown();
    }

    public function test_a_mapping_that_matches_the_schema_passes(): void
    {
        $this->artisan('site:verify')
            ->expectsOutputToContain('Sozlama sxemaga mos')
            ->assertSuccessful();
    }

    public function test_a_column_that_does_not_exist_fails_and_is_named(): void
    {
        config(['site.database.columns.cover' => 'rasm_yoq']);

        $this->artisan('site:verify')
            ->expectsOutputToContain('rasm_yoq')
            ->assertFailed();
    }

    public function test_a_missing_translations_table_fails(): void
    {
        config(['site.database.translations.table' => 'yoq_jadval']);

        $this->artisan('site:verify')
            ->expectsOutputToContain('jadval topilmadi')
            ->assertFailed();
    }

    /**
     * Majburiy ustunga qiymat yozmasak, INSERT saytda yiqiladi — buni
     * nashrdan oldin aytish kerak.
     */
    public function test_a_required_column_we_never_write_to_is_reported(): void
    {
        Schema::connection('site')->table('news', function (Blueprint $table) {
            $table->string('author')->default(null)->nullable(false);
        });

        $this->artisan('site:verify')
            ->expectsOutputToContain('author')
            ->assertFailed();
    }
}
