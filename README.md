# Institut yangiliklari — Telegramdan saytga avtomatik quvur

Institut Telegram kanalidagi `#elon` va `#yangilik` postlarini avtomatik
o'qiydi, Claude orqali rus va ingliz tillariga tarjima qiladi, moderator
tasdig'idan o'tkazadi va institut saytining e'lonlar / yangiliklar bo'limiga
joylaydi.

Bu **alohida servis**: institut saytining kodiga tegilmaydi, u bilan faqat
HTTP API orqali gaplashadi.

---

## Qanday ishlaydi

```
Telegram kanal                bu servis                         institut sayti
─────────────────    ────────────────────────────────    ────────────────────
#yangilik post  ──►  POST /telegram/webhook
   (+ rasmlar)         │  secret token tekshiriladi
                       │  update bazaga yoziladi (takrorlanmaslik uchun)
                       ▼
                     IngestTelegramUpdate
                       │  hashtag → tur (e'lon / yangilik)
                       │  entity'lar → HTML
                       │  albom bo'laklari bitta postga yig'iladi
                       ├──► DownloadPostMedia  (rasm/fayl yuklanadi)
                       ▼
                     TranslatePost  (20 s kechikish — albom to'lishini kutadi)
                       │  Claude: uz + ru + en, bitta so'rovda
                       ▼
                     holat: "Tekshiruvda"
                       │
                       ▼
                   ┌─────────────────────┐
                   │ Moderatsiya paneli  │  ← inson tahrirlaydi va tasdiqlaydi
                   │   /moderation       │
                   └─────────────────────┘
                       │  Tasdiqlash
                       ▼
                     PublishPost  ──────────────────►  POST {SITE_API_ENDPOINT}
                                                        (multipart: matn + rasmlar)
```

Moderatorsiz hech nima chop etilmaydi.

---

## Ishga tushirish uchun nimalar kerak

Quyidagi beshta narsani tayyorlash kerak:

| # | Nima | Qayerdan olinadi |
|---|------|------------------|
| 1 | **Telegram bot tokeni** | [@BotFather](https://t.me/BotFather) → `/newbot` |
| 2 | **Bot kanalga admin qilib qo'shilgan bo'lishi** | Kanal → Administrators → Add Admin. Busiz `channel_post` yangilanishlari **umuman kelmaydi** |
| 3 | **Kanal chat id** | Botni qo'shgach `php artisan telegram:webhook info`, yoki [@userinfobot](https://t.me/userinfobot) |
| 4 | **Anthropic API kaliti** | [console.anthropic.com](https://console.anthropic.com) → API Keys |
| 5 | **HTTPS domen** | Telegram webhookni faqat `https://` manziliga yuboradi (self-signed sertifikat ham bo'ladi, lekin Let's Encrypt osonroq) |

Sayt tomonida esa bitta endpoint yozilishi kerak — kontrakti quyida.

---

## O'rnatish

```bash
git clone <repo> && cd ttysi_yangilik
composer install
cp .env.example .env
php artisan key:generate

# .env ni to'ldiring (pastdagi jadvalga qarang), so'ng:
php artisan migrate
php artisan moderator:create          # moderatsiya paneliga kiruvchi hisob
php artisan telegram:webhook set      # webhookni Telegramga ro'yxatdan o'tkazadi
php artisan queue:work                # navbat ishchisi — doimiy ishlab turishi shart
```

`php artisan telegram:webhook info` — bot va webhook holatini ko'rsatadi
(shu yerda `pending_update_count` va oxirgi xato ko'rinadi).

### `.env` sozlamalari

| O'zgaruvchi | Ma'nosi |
|---|---|
| `APP_URL` | Servisning tashqi https manzili — webhook shundan yasaladi |
| `TELEGRAM_BOT_TOKEN` | BotFather tokeni |
| `TELEGRAM_WEBHOOK_SECRET` | Tasodifiy satr: `php -r "echo bin2hex(random_bytes(24));"` |
| `TELEGRAM_CHANNEL_ID` | `-100...` yoki `@kanal_nomi`. Bo'sh bo'lsa filtr o'chadi |
| `TELEGRAM_MEDIA_GROUP_WAIT` | Albomning to'lishini necha soniya kutish (standart 20) |
| `ANTHROPIC_API_KEY` | Claude API kaliti |
| `ANTHROPIC_MODEL` | Standart `claude-opus-5` |
| `ANTHROPIC_EFFORT` | `low`\|`medium`\|`high`\|`xhigh`\|`max` — standart `medium` |
| `TRANSLATE_TARGET_LOCALES` | Standart `ru,en` |
| `SITE_API_ENDPOINT` | Saytdagi qabul qiluvchi manzil |
| `SITE_API_TOKEN` | Sayt tekshiradigan Bearer token |
| `SITE_SECTION_ANNOUNCEMENT` / `SITE_SECTION_NEWS` | Saytdagi bo'lim sluglari |

Ishlab chiqarishda `QUEUE_CONNECTION=redis` va supervisor/systemd ostidagi
`queue:work` tavsiya etiladi; `database` drayveri ham ishlaydi.

---

## Kanalda post qanday yoziladi

Matnning istalgan joyiga hashtag qo'yiladi:

* `#elon` — saytning **e'lonlar** bo'limiga tushadi
* `#yangilik` — **yangiliklar** bo'limiga tushadi

Hashtagsiz postlar butunlay e'tiborsiz qoldiriladi. Hashtagning o'zi saytdagi
matndan olib tashlanadi.

Bir nechta rasm bitta albom qilib yuborilsa, hammasi bitta yangilikka
biriktiriladi; **birinchi rasm muqova** bo'ladi (moderator panelda buni
o'zgartira oladi). Telegram albomning izohini faqat bitta bo'lagiga
biriktiradi — servis bo'laklarni `media_group_id` bo'yicha bitta postga
yig'adi va izohni o'shandan oladi.

---

## Sayt tomonidagi endpoint (kontrakt)

Servis tasdiqlangan postni bitta `POST` bilan yuboradi:

```
POST {SITE_API_ENDPOINT}
Authorization: Bearer {SITE_API_TOKEN}
Content-Type: multipart/form-data
```

| Maydon | Tur | Izoh |
|---|---|---|
| `external_id` | string | `tg:{chat_id}:{message_id}` — **barqaror kalit**, takroriy joylashuvni oldini olish uchun shu bo'yicha `updateOrCreate` qiling |
| `type` | string | `announcement` \| `news` |
| `section` | string | bo'lim sluggi (`elonlar` / `yangiliklar`) |
| `source_url` | string\|null | kanaldagi asl post havolasi |
| `posted_at` | string\|null | ISO-8601, kanalda chiqqan vaqti |
| `translations` | JSON string | `{"uz": {...}, "ru": {...}, "en": {...}}`, har biri `title`, `summary`, `body_html` |
| `cover_index` | string | `images[]` ichidagi muqova rasmining tartib raqami |
| `images[]` | file[] | rasmlar va hujjatlar |

Kutilgan javob: `200 OK` + `{"id": "...", "url": "https://..."}`.
`url` moderatsiya panelida "Saytda" havolasi bo'lib ko'rinadi.

`body_html` faqat quyidagi teglardan iborat bo'ladi: `<p> <br> <strong> <em>
<u> <s> <a href> <ul> <ol> <li> <blockquote> <code> <pre>`. Shunga qaramay
saytda chiqarishdan oldin **HTML purifier** orqali o'tkazish tavsiya etiladi.

Laravel saytida qabul qiluvchi tomonning eng qisqa ko'rinishi:

```php
Route::post('/api/news', function (Request $request) {
    abort_unless($request->bearerToken() === config('services.pipeline.token'), 401);

    $data = $request->validate([
        'external_id' => ['required', 'string'],
        'type' => ['required', 'in:announcement,news'],
        'translations' => ['required', 'json'],
        'images.*' => ['file', 'max:20480'],
    ]);

    $news = News::updateOrCreate(
        ['external_id' => $data['external_id']],
        [
            'section' => $request->string('section'),
            'published_at' => $request->date('posted_at'),
            'source_url' => $request->input('source_url'),
        ],
    );

    foreach (json_decode($data['translations'], true) as $locale => $t) {
        $news->translations()->updateOrCreate(['locale' => $locale], [
            'title' => $t['title'],
            'summary' => $t['summary'],
            'body' => clean($t['body_html']),   // mewebstudio/purifier
        ]);
    }

    // ... images[] ni saqlash, cover_index bo'yicha muqovani belgilash

    return ['id' => $news->id, 'url' => route('news.show', $news)];
});
```

---

## Moderatsiya paneli

`/moderation` — kirish uchun `moderator:create` bilan yaratilgan hisob kerak.

Har bir post uchun: kanaldagi asl matn, har bir til bo'yicha sarlavha /
qisqacha mazmun / HTML matn (tahrirlanadigan), rasmlar va muqova tanlovi.
Tugmalar: **Saqlash**, **Tasdiqlash va saytga joylash**, **Qayta tarjima
qilish**, **Rad etish**.

Moderator qo'l bilan tuzatgan tarjima `edited_by_human` deb belgilanadi va
keyingi qayta tarjimalar uni **bosib ketmaydi**.

### Post holatlari

`Yig'ilmoqda` → `Tarjima qilinmoqda` → `Tekshiruvda` → `Tasdiqlangan` →
`Chop etilgan`. Yon tarmoqlar: `Rad etilgan`, `Xato` (sabab panelda
ko'rinadi, "Qayta tarjima" bilan qaytariladi).

---

## Xarajat

Bitta post uchun taxminan (Claude Opus 5, ~300 so'zli yangilik, 3 til):

* kirish ~1 100 token → ~$0.006
* chiqish ~1 800 token → ~$0.045

Jami **~$0.05 / post**. Oyiga 50 ta post ≈ **$2.5**. Tizim prompti
keshlanadi, shuning uchun kirish qismi amalda undan ham arzonroq tushadi.
Arzonlashtirish kerak bo'lsa `ANTHROPIC_EFFORT=low` yoki
`ANTHROPIC_MODEL=claude-sonnet-5` qilinadi.

---

## Loyiha tuzilishi

| Fayl | Vazifasi |
|---|---|
| `app/Http/Controllers/TelegramWebhookController.php` | webhook: secret tekshiruvi, takrorlanishni oldini olish |
| `app/Services/Telegram/PostParser.php` | Telegram entity'larini HTMLga o'girish (UTF-16 offsetlar), hashtag aniqlash |
| `app/Services/Telegram/TelegramClient.php` | Bot API chaqiriqlari, fayl yuklash |
| `app/Services/Translation/Translator.php` | Claude chaqirig'i, structured output sxemasi, tarjima prompti |
| `app/Services/Site/SitePublisher.php` | saytga multipart so'rov |
| `app/Jobs/*` | navbat vazifalari: ingest → download → translate → publish |
| `app/Http/Controllers/ModerationController.php` | moderatsiya paneli |

Testlar: `php artisan test` (36 ta test). Kod uslubi: `vendor/bin/pint`.

---

## Hali qilinmagan / keyingi qadamlar

* Sayt tomonidagi qabul qiluvchi endpoint — yuqoridagi kontrakt bo'yicha
  institut saytida yozilishi kerak.
* Kanaldagi post **tahrirlansa** (`edited_channel_post`) hozir yangi tarjima
  ketmaydi — allaqachon chop etilgan postni yangilash mantiqi qo'shilmagan.
* Postni kanaldan o'chirish saytdagi nusxani o'chirmaydi.
* Rasmlar lokal diskda saqlanadi; S3 kerak bo'lsa `FILESYSTEM_DISK` orqali.
* Moderatorga yangi post kelgani haqida xabar (Telegram/email) yuborilmaydi.
