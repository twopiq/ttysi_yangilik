<?php

return [
    /*
    | Postni saytga yetkazish usuli.
    |
    | api      — saytning HTTP endpointiga multipart so'rov (standart).
    | database — sayt bazasiga to'g'ridan-to'g'ri yozish. Sxemani
    |            `php artisan site:inspect` bilan aniqlab, quyidagi
    |            `database` bo'limidagi moslikni to'ldiring.
    */
    'driver' => env('SITE_DRIVER', 'api'),

    /*
    | Laravel saytning yangilik qabul qiladigan API manzili.
    | Masalan: https://institut.uz/api/news
    */
    'endpoint' => env('SITE_API_ENDPOINT'),

    /*
    | Bearer token yoki API kalit. Sayt tomonida shu token tekshiriladi.
    */
    'token' => env('SITE_API_TOKEN'),

    'timeout' => (int) env('SITE_API_TIMEOUT', 30),

    /*
    | Post turi -> saytdagi bo'lim (rubrika) sluggi.
    */
    'sections' => [
        'announcement' => env('SITE_SECTION_ANNOUNCEMENT', 'elonlar'),
        'news' => env('SITE_SECTION_NEWS', 'yangiliklar'),
    ],

    /*
    |-------------------------------------------------------------------------
    | `database` usuli: sayt jadvallariga moslik
    |-------------------------------------------------------------------------
    |
    | Har bir saytning sxemasi har xil, shuning uchun ustun nomlari shu yerda
    | moslanadi. `php artisan site:inspect` haqiqiy nomlarni ko'rsatadi.
    | Qiymati null bo'lgan ustun umuman yozilmaydi.
    |
    */
    'database' => [
        'connection' => env('SITE_DB_CONNECTION', 'site'),

        'table' => env('SITE_DB_TABLE', 'news'),
        'primary_key' => env('SITE_DB_PRIMARY_KEY', 'id'),

        /*
        | Ko'p tillilik qanday saqlanadi:
        |   translations — alohida jadval, har til uchun qator (Astrotomic)
        |   json         — JSON ustunlar: {"uz": "...", "ru": "..."} (Spatie)
        |   columns      — til qo'shimchali ustunlar: title_uz, title_ru
        */
        'layout' => env('SITE_DB_LAYOUT', 'translations'),

        /*
        | Asosiy jadvalning ustunlari.
        */
        'columns' => [
            'slug' => 'slug',
            'published_at' => 'published_at',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
            'cover' => 'image',
            'source_url' => null,
        ],

        /*
        | Har bir yozuvga doimiy qo'yiladigan qiymatlar — masalan muallif id,
        | chop etilgan bayrog'i. Post turiga qarab ajratiladi.
        */
        'defaults' => [
            'common' => [],
            'announcement' => [],
            'news' => [],
        ],

        /*
        | layout = translations
        */
        'translations' => [
            'table' => 'news_translations',
            'foreign_key' => 'news_id',
            'locale_column' => 'locale',
            'columns' => [
                'title' => 'title',
                'summary' => 'description',
                'body' => 'body',
            ],
        ],

        /*
        | layout = json — asosiy jadvaldagi JSON ustunlar.
        */
        'json' => [
            'title' => 'title',
            'summary' => 'description',
            'body' => 'body',
        ],

        /*
        | layout = columns — {locale} o'rniga til kodi qo'yiladi.
        */
        'suffixed' => [
            'title' => 'title_{locale}',
            'summary' => 'description_{locale}',
            'body' => 'body_{locale}',
        ],

        /*
        | Qo'shimcha rasmlar jadvali. null bo'lsa faqat muqova yoziladi.
        */
        'media' => [
            'table' => 'news_images',
            'foreign_key' => 'news_id',
            'path_column' => 'path',
            'position_column' => 'sort_order',
        ],

        /*
        | Rasm fayllari saytning storage papkasiga ko'chiriladi.
        |
        |   path           — saytdagi absolyut papka, masalan
        |                    /var/www/institut/storage/app/public
        |   subdirectory   — shu papka ichidagi ost-papka
        |   stored_prefix  — bazaga yoziladigan nisbiy yo'l boshlanishi
        |
        | `path` bo'sh bo'lsa fayllar ko'chirilmaydi va rasm yo'llari
        | yozilmaydi — matn baribir chop etiladi.
        */
        'storage' => [
            'path' => env('SITE_STORAGE_PATH'),
            'subdirectory' => env('SITE_STORAGE_SUBDIR', 'news'),
            'stored_prefix' => env('SITE_STORED_PREFIX', 'news'),
        ],

        /*
        | Moderatsiya panelida "Saytda" havolasi uchun. {slug} va {id} ishlaydi.
        */
        'url_template' => env('SITE_URL_TEMPLATE'),
    ],
];
