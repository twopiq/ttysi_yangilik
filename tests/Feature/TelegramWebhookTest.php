<?php

namespace Tests\Feature;

use App\Jobs\IngestTelegramUpdate;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TelegramFixtures;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_a_request_without_the_secret_header(): void
    {
        Queue::fake();

        $this->postJson('/telegram/webhook', TelegramFixtures::textPost())
            ->assertForbidden();

        $this->assertDatabaseCount('telegram_updates', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_rejects_a_wrong_secret(): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'boshqa-secret')
            ->postJson('/telegram/webhook', TelegramFixtures::textPost())
            ->assertForbidden();
    }

    public function test_it_stores_the_update_and_queues_ingestion(): void
    {
        Queue::fake();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/telegram/webhook', TelegramFixtures::textPost())
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('telegram_updates', ['update_id' => 100001]);
        Queue::assertPushed(IngestTelegramUpdate::class);
    }

    /** Telegram javobni olmasa o'sha update'ni qayta yuboradi — ikki marta ishlanmasligi kerak. */
    public function test_a_repeated_update_id_is_ignored(): void
    {
        Queue::fake();

        for ($i = 0; $i < 2; $i++) {
            $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
                ->postJson('/telegram/webhook', TelegramFixtures::textPost())
                ->assertOk();
        }

        $this->assertSame(1, TelegramUpdate::count());
        Queue::assertPushed(IngestTelegramUpdate::class, 1);
    }
}
