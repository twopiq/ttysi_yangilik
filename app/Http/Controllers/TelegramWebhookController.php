<?php

namespace App\Http\Controllers;

use App\Jobs\IngestTelegramUpdate;
use App\Models\TelegramUpdate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    /**
     * Telegram webhook. Javob tez qaytishi kerak — Telegram sekin javobni
     * muvaffaqiyatsiz deb hisoblab, update'ni qayta yuboradi. Shu sababli
     * bu yerda faqat saqlab, ishni navbatga o'tkazamiz.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $expected = config('telegram.webhook_secret');
        $received = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (blank($expected) || ! is_string($received) || ! hash_equals($expected, $received)) {
            Log::warning('Telegram webhook: secret token mos kelmadi', ['ip' => $request->ip()]);

            return response()->json(['ok' => false], 403);
        }

        $payload = $request->all();
        $updateId = $payload['update_id'] ?? null;

        if (! is_int($updateId) && ! ctype_digit((string) $updateId)) {
            return response()->json(['ok' => true]);
        }

        try {
            $update = TelegramUpdate::create([
                'update_id' => (int) $updateId,
                'payload' => $payload,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Telegram javobni olmasa o'sha update'ni qayta yuboradi — bu normal.
            return response()->json(['ok' => true]);
        }

        IngestTelegramUpdate::dispatch($update->id);

        return response()->json(['ok' => true]);
    }
}
