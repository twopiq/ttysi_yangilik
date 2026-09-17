<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Throwable;

class TelegramWebhookCommand extends Command
{
    protected $signature = 'telegram:webhook
                            {action=info : set | info | delete}
                            {--url= : Webhook manzili (bo\'sh bo\'lsa APP_URL dan olinadi)}';

    protected $description = 'Telegram webhookni sozlaydi yoki holatini ko\'rsatadi';

    public function handle(TelegramClient $telegram): int
    {
        try {
            return match ($this->argument('action')) {
                'set' => $this->set($telegram),
                'delete' => $this->delete($telegram),
                default => $this->info_($telegram),
            };
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function set(TelegramClient $telegram): int
    {
        $secret = config('telegram.webhook_secret');

        if (blank($secret)) {
            $this->error('TELEGRAM_WEBHOOK_SECRET sozlanmagan. Tasodifiy qiymat yarating:');
            $this->line('  php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"');

            return self::FAILURE;
        }

        $url = $this->option('url') ?: rtrim((string) config('app.url'), '/').'/telegram/webhook';

        if (! str_starts_with($url, 'https://')) {
            $this->error("Telegram faqat https manzilni qabul qiladi. Berilgan: {$url}");

            return self::FAILURE;
        }

        $telegram->setWebhook($url, $secret);
        $this->info("Webhook o'rnatildi: {$url}");

        return self::SUCCESS;
    }

    private function delete(TelegramClient $telegram): int
    {
        $telegram->deleteWebhook();
        $this->info("Webhook o'chirildi.");

        return self::SUCCESS;
    }

    private function info_(TelegramClient $telegram): int
    {
        $me = $telegram->getMe();
        $this->line("Bot: @{$me['username']} ({$me['id']})");

        $info = $telegram->getWebhookInfo();

        foreach (['url', 'pending_update_count', 'last_error_date', 'last_error_message', 'ip_address'] as $key) {
            if (isset($info[$key]) && $info[$key] !== '') {
                $this->line(str_pad($key, 22).': '.(is_scalar($info[$key]) ? $info[$key] : json_encode($info[$key])));
            }
        }

        return self::SUCCESS;
    }
}
