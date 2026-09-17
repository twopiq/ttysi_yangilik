<?php

return [
    'api_key' => env('ANTHROPIC_API_KEY'),

    'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),

    'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 16000),

    /*
    | Tarjima aniqlikni talab qiladi, lekin ijodkorlikni emas — shuning uchun
    | "medium" yetarli va arzonroq. low|medium|high|xhigh|max.
    */
    'effort' => env('ANTHROPIC_EFFORT', 'medium'),

    /*
    | Manba til (kanaldagi postlar tili) va tarjima qilinadigan tillar.
    */
    'source_locale' => env('TRANSLATE_SOURCE_LOCALE', 'uz'),

    'target_locales' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRANSLATE_TARGET_LOCALES', 'ru,en'))
    ))),

    'locale_names' => [
        'uz' => "o'zbek (lotin)",
        'ru' => 'rus',
        'en' => 'ingliz',
        'uz-cyrl' => "o'zbek (kirill)",
    ],
];
