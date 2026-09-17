<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->name('telegram.webhook');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->prefix('moderation')->name('moderation.')->group(function () {
    Route::get('/', [ModerationController::class, 'index'])->name('index');
    // `{post}` dan oldin turishi shart, aks holda "media" post id deb o'qiladi.
    Route::get('/media/{media}', [ModerationController::class, 'media'])->name('media');
    Route::get('/{post}', [ModerationController::class, 'edit'])
        ->whereNumber('post')
        ->name('edit');
    Route::put('/{post}', [ModerationController::class, 'update'])->name('update');
    Route::post('/{post}/approve', [ModerationController::class, 'approve'])->name('approve');
    Route::post('/{post}/reject', [ModerationController::class, 'reject'])->name('reject');
    Route::post('/{post}/retranslate', [ModerationController::class, 'retranslate'])->name('retranslate');
});

Route::redirect('/', '/moderation');
