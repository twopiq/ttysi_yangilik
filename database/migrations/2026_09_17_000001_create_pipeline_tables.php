<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Telegram yuborgan har bir update — idempotentlik uchun.
        Schema::create('telegram_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('update_id')->unique();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chat_id');
            $table->unsignedBigInteger('message_id');
            $table->string('media_group_id')->nullable();
            $table->string('type');                       // announcement | news
            $table->string('status')->default('collecting');
            $table->text('source_text');                  // hashtaglar olib tashlangan asl matn
            $table->text('source_html')->nullable();      // Telegram entity'lardan yasalgan HTML
            $table->string('source_locale', 12)->default('uz');
            $table->string('telegram_url')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('published_url')->nullable();
            $table->string('remote_id')->nullable();      // saytdagi yozuv id'si
            $table->timestamp('posted_at')->nullable();   // kanalda chiqqan vaqti
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['chat_id', 'message_id']);
            $table->index(['status', 'created_at']);
            $table->index('media_group_id');
        });

        Schema::create('post_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('title');
            $table->text('body');                         // HTML
            $table->string('summary', 500)->nullable();
            $table->boolean('edited_by_human')->default(false);
            $table->timestamps();

            $table->unique(['post_id', 'locale']);
        });

        Schema::create('post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('file_id');                    // Telegram file_id
            $table->string('file_unique_id')->nullable();
            $table->string('kind')->default('photo');     // photo | document
            $table->string('path')->nullable();           // storage'dagi yo'l
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->boolean('is_cover')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->text('download_error')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'file_unique_id']);
            $table->index(['post_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_media');
        Schema::dropIfExists('post_translations');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('telegram_updates');
    }
};
