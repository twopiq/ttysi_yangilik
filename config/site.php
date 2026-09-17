<?php

return [
    /*
    | Postni saytga yetkazish usuli.
    |
    | api — saytning HTTP endpointiga multipart so'rov (standart).
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
];
