<?php

return [
    /*
    |---------------------------------------------------------------------
    | Tarjima dvigateli
    |---------------------------------------------------------------------
    |
    | gemini            — Google Gemini API. Bepul darajasi bor, tavsiya etiladi.
    | openai_compatible — OpenAI `chat/completions` shakliga mos har qanday
    |                     servis: OpenRouter (bepul modellar), Groq, Together,
    |                     lokal Ollama. Faqat base_url va model o'zgaradi.
    | claude            — Anthropic Claude. Sifati eng yuqori, API kaliti pullik.
    | manual            — hech qanday API yo'q: matn moderatsiya paneliga
    |                     tushadi va tarjimani moderator o'zi yozadi.
    |
    */
    'driver' => env('TRANSLATE_DRIVER', 'gemini'),

    'source_locale' => env('TRANSLATE_SOURCE_LOCALE', 'uz'),

    'target_locales' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRANSLATE_TARGET_LOCALES', 'ru,en')),
    ))),

    'locale_names' => [
        'uz' => "o'zbek (lotin)",
        'ru' => 'rus',
        'en' => 'ingliz',
        'uz-cyrl' => "o'zbek (kirill)",
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 120),
    ],

    'openai_compatible' => [
        'api_key' => env('TRANSLATE_OPENAI_API_KEY'),
        'model' => env('TRANSLATE_OPENAI_MODEL', 'meta-llama/llama-3.3-70b-instruct:free'),
        'base_url' => env('TRANSLATE_OPENAI_BASE_URL', 'https://openrouter.ai/api/v1'),
        'timeout' => (int) env('TRANSLATE_OPENAI_TIMEOUT', 120),
    ],

    'claude' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 16000),
        'effort' => env('ANTHROPIC_EFFORT', 'medium'),
    ],
];
