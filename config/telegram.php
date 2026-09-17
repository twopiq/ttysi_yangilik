<?php

return [
    /*
    | BotFather bergan token. Bot manba kanalga admin qilib qo'shilgan bo'lishi shart,
    | aks holda `channel_post` yangilanishlari kelmaydi.
    */
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),

    /*
    | setWebhook chaqirig'idagi secret_token. Telegram uni har bir so'rovda
    | X-Telegram-Bot-Api-Secret-Token sarlavhasida qaytaradi.
    */
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),

    /*
    | Faqat shu kanaldan kelgan postlar qabul qilinadi. Raqamli chat id
    | (masalan -1001234567890) yoki @username. Bo'sh bo'lsa filtr o'chiriladi.
    */
    'channel_id' => env('TELEGRAM_CHANNEL_ID'),

    'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),

    /*
    | Hashtag -> post turi. Kalit kichik harfda, # belgisisiz.
    */
    'hashtag_types' => [
        'elon' => 'announcement',
        'e\'lon' => 'announcement',
        'yangilik' => 'news',
    ],

    /*
    | Albom (media_group) bo'lak-bo'lak keladi. Oxirgi bo'lakni kutish uchun
    | tarjima shuncha soniyaga kechiktiriladi.
    */
    'media_group_wait_seconds' => (int) env('TELEGRAM_MEDIA_GROUP_WAIT', 20),

    /*
    | Telegram getFile orqali yuklab olinadigan fayl uchun maksimal hajm (bayt).
    | Bot API limiti 20 MB.
    */
    'max_file_bytes' => (int) env('TELEGRAM_MAX_FILE_BYTES', 20 * 1024 * 1024),
];
