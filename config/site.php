<?php

return [
    /*
    | Postni saytga yetkazish usuli.
    |
    | api      — saytning HTTP endpointiga multipart so'rov (standart).
    | database — sayt bazasiga to'g'ridan-to'g'ri yozish. Sxemani
    |            `php artisan site:inspect` bilan aniqlab, quyidagi
    |            `database` bo'limidagi moslikni to'ldiring.
    | admin    — saytning admin paneliga kirib, "yangilik qo'shish" formasini
    |            to'ldirish. Saytda API bo'lmasa va kodiga tegish imkoni
    |            bo'lmasa shu ishlatiladi. Maydon nomlarini
    |            `php artisan site:form` panelning o'zidan o'qib beradi.
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

    /*
    |-------------------------------------------------------------------------
    | `admin` usuli: saytning admin paneli orqali joylash
    |-------------------------------------------------------------------------
    |
    | Saytda API yo'q va uning kodiga tegilmaydi — shuning uchun panelga
    | kirib, forma to'ldiriladi. Maydon nomlarini bilish uchun:
    |
    |     php artisan site:form
    |
    */
    'admin' => [
        'base_url' => env('SITE_ADMIN_URL'),

        'login_path' => env('SITE_ADMIN_LOGIN_PATH', '/login'),
        'create_path' => env('SITE_ADMIN_CREATE_PATH', '/news/create'),

        'username' => env('SITE_ADMIN_USERNAME'),
        'password' => env('SITE_ADMIN_PASSWORD'),

        /*
        | Kirish formasidagi maydon nomlari. Ba'zi panellarda `login`,
        | `username` yoki `phone` bo'ladi — `site:form --login` ko'rsatadi.
        */
        'login_fields' => [
            'username' => env('SITE_ADMIN_USERNAME_FIELD', 'email'),
            'password' => env('SITE_ADMIN_PASSWORD_FIELD', 'password'),
        ],

        /*
        | Sahifada bir nechta forma bo'lsa, action'i shu matnni o'z ichiga
        | olgani tanlanadi. Bo'sh bo'lsa: fayl maydoni bori, so'ng maydoni
        | eng ko'pi olinadi.
        */
        'action_hint' => env('SITE_ADMIN_ACTION_HINT'),

        /*
        | Forma maydonlariga moslik. `{locale}` bo'lsa har bir til uchun
        | alohida maydon to'ldiriladi (masalan `title[uz]`, `title_ru`).
        | `{locale}` bo'lmasa panel bir tilli — faqat manba tili yuboriladi.
        */
        'fields' => [
            'title' => env('SITE_ADMIN_TITLE_FIELD', 'title[{locale}]'),
            'summary' => env('SITE_ADMIN_SUMMARY_FIELD'),
            'body' => env('SITE_ADMIN_BODY_FIELD', 'body[{locale}]'),
        ],

        /*
        | Rasm maydonlari. `image_field` — muqova, `gallery_field` — qolgan
        | rasmlar (masalan `images[]`). Ikkisi ham bo'sh bo'lsa rasm
        | yuborilmaydi.
        */
        'image_field' => env('SITE_ADMIN_IMAGE_FIELD', 'image'),
        'gallery_field' => env('SITE_ADMIN_GALLERY_FIELD'),

        /*
        | Formaning boshqa majburiy maydonlari: rubrika, holat va hokazo.
        | Post turiga qarab ajratiladi.
        */
        'extra' => [
            'common' => [],
            'announcement' => [],
            'news' => [],
        ],

        'timeout' => (int) env('SITE_ADMIN_TIMEOUT', 60),
        'user_agent' => env('SITE_ADMIN_USER_AGENT', 'InstitutYangiliklar/1.0'),

        /*
        | Moderatsiya panelidagi havola. Bo'sh bo'lsa yuborilgandan keyin
        | panel yo'naltirgan manzil ishlatiladi.
        */
        'url_template' => env('SITE_ADMIN_URL_TEMPLATE'),
    ],
];
