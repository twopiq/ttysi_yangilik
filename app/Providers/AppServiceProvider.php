<?php

namespace App\Providers;

use App\Contracts\TranslationDriver;
use App\Services\Translation\ClaudeTranslator;
use App\Services\Translation\GeminiTranslator;
use App\Services\Translation\ManualTranslator;
use App\Services\Translation\OpenAiCompatibleTranslator;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Sozlamadagi nom bo'yicha dvigatelni tanlaydigan sinflar.
     *
     * @var array<string, class-string<TranslationDriver>>
     */
    private const TRANSLATION_DRIVERS = [
        'gemini' => GeminiTranslator::class,
        'openai_compatible' => OpenAiCompatibleTranslator::class,
        'claude' => ClaudeTranslator::class,
        'manual' => ManualTranslator::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TranslationDriver::class, function (): TranslationDriver {
            $driver = (string) config('translation.driver');

            if (! isset(self::TRANSLATION_DRIVERS[$driver])) {
                $known = implode(', ', array_keys(self::TRANSLATION_DRIVERS));

                throw new InvalidArgumentException(
                    "Noma'lum tarjima dvigateli: '{$driver}'. Mavjudlari: {$known}.",
                );
            }

            return $this->app->make(self::TRANSLATION_DRIVERS[$driver]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
