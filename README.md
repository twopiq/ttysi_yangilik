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
| 4 | **Tarjima kaliti** (yoki umuman kalitsiz) | pastdagi «Tarjima dvigatelini tanlash» bo'limiga qarang |
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
| `TRANSLATE_DRIVER` | `gemini` \| `openai_compatible` \| `claude` \| `manual` |
| `TRANSLATE_TARGET_LOCALES` | Standart `ru,en` |
| `GEMINI_API_KEY` / `GEMINI_MODEL` | `gemini` dvigateli uchun |
| `TRANSLATE_OPENAI_BASE_URL` / `_API_KEY` / `_MODEL` | `openai_compatible` uchun |
| `ANTHROPIC_API_KEY` / `ANTHROPIC_MODEL` | `claude` uchun |
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

## Saytga yetkazishning uchta usuli

`SITE_DRIVER` uch qiymatni oladi. Tanlash mezoni oddiy: saytga kod
qo'shish imkoni bormi, baza ochiqmi, yoki faqat admin paneliga login bormi.

| `SITE_DRIVER` | Nima kerak | Qachon |
|---|---|---|
| `api` | saytga kod qo'shish | Eng toza: saytning validatsiyasi, slug va keshi joyida qoladi |
| `database` | baza login/paroli + storage papkasiga yo'l | Kod qo'shadigan odam bo'lmasa, baza esa ochiq bo'lsa |
| `admin` | faqat admin panel login/paroli | Saytda API yo'q, kodiga tegilmaydi, bazaga ham yo'l yo'q |

### `database` — sayt bazasiga to'g'ridan-to'g'ri yozish

Sayt bilan bir tarmoq ichida bo'lsangiz va saytga kod qo'shadigan odam
bo'lmasa, shu usul ishlatiladi. Sayt tomonida **hech narsa yozilmaydi**.

Sxemani hech kimdan so'rash shart emas — baza o'zi aytadi:

```bash
php artisan site:inspect            # jadvallar, ustunlar, tashqi kalitlar
php artisan site:inspect news --sample   # bitta jadval + namuna qator
php artisan site:inspect --all      # barcha jadvallar ro'yxati
```

Buyruq yangilikka o'xshagan jadvallarni o'zi topadi va ko'p tillilik qaysi
ko'rinishda saqlanganini aniqlaydi. Topilganini `config/site.php` ichidagi
`database` bo'limiga ko'chirasiz, so'ng:

```bash
php artisan site:verify   # moslikni sxemaga solishtiradi, hech narsa yozmaydi
```

`site:verify` yo'q ustunni, yo'q jadvalni va **biz yozmaydigan majburiy
ustunni** oldindan aytadi — xato nashr paytida emas, sozlash paytida
chiqadi.

Uch xil ko'p tillilik qo'llab-quvvatlanadi (`SITE_DB_LAYOUT`):

| Qiymat | Sxema |
|---|---|
| `translations` | `news` + `news_translations(news_id, locale, title, body)` |
| `json` | `news.title` JSON: `{"uz": "...", "ru": "..."}` |
| `columns` | `news.title_uz`, `news.title_ru`, ... |

**Takroriy nashr yangi yozuv yaratmaydi.** Birinchi nashrda olingan id
bizning `posts.remote_id` da saqlanadi, keyingisida o'sha qator yangilanadi
— saytda maxsus `external_id` ustuni talab qilinmaydi.

**Rasmlar.** Baza porti fayllarni tashimaydi, shuning uchun
`SITE_STORAGE_PATH` saytning `storage/app/public` papkasini ko'rsatishi
kerak (bir xil server yoki NFS/SMB mount). Fayl o'sha yerga ko'chiriladi,
bazaga esa nisbiy yo'l yoziladi. `SITE_STORAGE_PATH` bo'sh bo'lsa matn
baribir chop etiladi, faqat rasmsiz.

**Nimadan ehtiyot bo'lish kerak.** Bu usul saytning Eloquent modelini
chetlab o'tadi: model eventlari, kesh tozalash va qidiruv indeksi
ishlamaydi. Sayt migratsiya qilib ustun nomini o'zgartirsa, `site:verify`
buni ko'rsatadi, lekin avtomatik tuzatmaydi — sayt yangilangach shu
buyruqni qayta ishlating.

### `admin` — admin panel orqali joylash

Saytda API yo'q, kodiga tegish imkoni yo'q, bazaga ham yo'l yo'q — qoladigan
narsa admin panel login/paroli. Bu usul odam qiladigan ishni bajaradi:
panelga kiradi, "yangilik qo'shish" formasini to'ldiradi, yuboradi.

Maydon nomlarini bilish uchun ikki buyruq bor. Ikkisi ham **hech narsa
yubormaydi**:

```bash
php artisan site:form --login    # kirish formasidagi maydon nomlari
php artisan site:form            # panelga kirib, yangilik formasini o'qiydi
```

`site:form` maydonlarni jadval qilib chiqaradi va taxminiy moslikni tayyorlab
beradi, masalan:

```
 'title' => 'title[{locale}]',
 'summary' => 'anons[{locale}]',
 'body' => 'content[{locale}]',
 'image_field' => 'rasm',

 WARN Bu maydonlar majburiy, lekin mosliqqa tushmadi: category_id
```

Shu blokni `config/site.php` -> `admin` ga ko'chirasiz, ogohlantirishdagi
majburiy maydonlarga esa `admin.extra` da doimiy qiymat berasiz (rubrika id,
muallif va shu kabilar).

Maydon nomida til kodi bo'lsa (`title[uz]`, `name_ru`) buyruq uni
`{locale}` shabloniga aylantiradi — shunda barcha tillar bitta yozuvda
to'ldiriladi. Shablonda `{locale}` bo'lmasa panel bir tilli deb qabul
qilinadi va faqat manba tili yuboriladi.

**Tarmoq yopiq bo'lsa yoki panel JavaScript bilan yasalgan bo'lsa:**
sahifani brauzerda saqlab (Ctrl+S), faylni shu buyruqqa berasiz — login ham,
tarmoq ham kerak emas:

```bash
php artisan site:form --file=storage/app/panel.html
```

Nima avtomatik hal qilinadi:

* Laravel `_token` (CSRF) formaning o'zidan olinadi, sessiya cookie saqlanadi
* formadagi yashirin maydonlar (`author_id` va hokazo) o'zgarmagan qaytariladi
* muqova `image_field` ga, qolgan rasmlar `gallery_field` ga biriktiriladi
* sayt validatsiya xatosi bersa, sababi moderatsiya panelida ko'rinadi
  (`Sayt aytgan xatolar: category_id tanlanmagan`)

**Bu uchtasining eng mo'rti.** Panel HTML'i o'zgarsa ishlamay qoladi —
shuning uchun maydon nomlari kodda emas, sozlamada turadi va `site:form`
ularni qaytadan o'qib beradi. Livewire, Inertia, Filament yoki Nova
panellari formani JavaScript orqali yuboradi; `site:form` buni aniqlab
ogohlantiradi, chunki u yerda oddiy POST ishlamasligi mumkin. Panelda ikki
faktorli tasdiq yoki captcha bo'lsa ham bu usul ishlamaydi.

### `api` — saytning HTTP endpointiga yuborish

Saytga kod qo'sha oladigan odam bo'lsa, shu usul tozaroq: saytning o'z
validatsiyasi, slug yasashi va keshi joyida qoladi. Kontrakti quyida.

#### `api` usulining kontrakti

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

## Tarjima dvigatelini tanlash

Tarjima qismi almashtiriladigan qilib yozilgan: `TRANSLATE_DRIVER` ni
o'zgartirasiz, quvurning qolgan qismiga tegilmaydi.

| Dvigatel | Kalit kerakmi | Izoh |
|---|---|---|
| `gemini` | Google AI Studio kaliti (bepul darajasi bor) | **Standart tanlov.** O'zbek tilini yaxshi biladi, javob shakli `responseSchema` bilan kafolatlanadi |
| `openai_compatible` | servisga qarab | Bitta dvigatel bilan OpenRouter (`:free` modellar), Groq, Together, Mistral va lokal Ollama ishlaydi — faqat `base_url` va `model` o'zgaradi |
| `claude` | Anthropic kaliti (pullik) | Sifati eng yuqori |
| `manual` | **hech narsa kerak emas** | Matn moderatsiya paneliga tushadi, tarjimani moderator o'zi yozadi. Rasm, tasdiqlash va saytga yuborish odatdagidek ishlaydi |

Institut kanali hajmi kichik — kuniga bir necha post, ya'ni sutkasiga 1-3 ta
so'rov. Bu deyarli har qanday bepul darajaning limitidan ancha past.

Limit tugasa (HTTP 429) vazifa xato bilan tugamaydi: navbat uni 30 s, 2 daq,
10 daq oralig'ida qayta urinib ko'radi.

### Nega oddiy tarjimon (Google Translate, Yandex Translate) ishlatilmagan

Quvurga faqat tarjima emas, uchta narsa kerak: **sarlavha yasash**,
**qisqacha mazmun yozish** va **Telegram uslubini sayt uslubiga keltirish**
(emoji, "obuna bo'ling", "👇" kabilarni olib tashlash). Oddiy mashina
tarjimasi matnni satrma-satr o'giradi, lekin sarlavha ham, qisqacha mazmun
ham yasay olmaydi. Shuning uchun dvigatellar til modellari asosida.

Agar baribir Yandex/Google Translate ishlatmoqchi bo'lsangiz, `manual`
dvigatelini yoqing va moderator panelida tarjimani qo'lda joylashtiring.

### Taxminiy xarajat

`gemini` yoki `openai_compatible` bepul darajasida — **$0**.

`claude` tanlansa, bitta post uchun taxminan (~300 so'zli yangilik, 3 til):
kirish ~1 100 token → ~$0.006, chiqish ~1 800 token → ~$0.045. Jami
**~$0.05 / post**, oyiga 50 ta post ≈ **$2.5**.

## Loyiha tuzilishi

| Fayl | Vazifasi |
|---|---|
| `app/Http/Controllers/TelegramWebhookController.php` | webhook: secret tekshiruvi, takrorlanishni oldini olish |
| `app/Services/Telegram/PostParser.php` | Telegram entity'larini HTMLga o'girish (UTF-16 offsetlar), hashtag aniqlash |
| `app/Services/Telegram/TelegramClient.php` | Bot API chaqiriqlari, fayl yuklash |
| `app/Contracts/TranslationDriver.php` | tarjima dvigateli shartnomasi |
| `app/Services/Translation/TranslationPrompt.php` | umumiy prompt, JSON sxemasi, javobni o'qish |
| `app/Services/Translation/*Translator.php` | Gemini, OpenAI-mos, Claude va qo'lda dvigatellari |
| `app/Contracts/SitePublisher.php` | saytga yetkazish shartnomasi |
| `app/Services/Site/ApiSitePublisher.php` | saytga multipart HTTP so'rov |
| `app/Services/Site/DatabaseSitePublisher.php` | sayt bazasiga to'g'ridan-to'g'ri yozish |
| `app/Services/Site/AdminSitePublisher.php` | admin panel formasini to'ldirish |
| `app/Services/Site/AdminPanel/FormParser.php` | HTML formani ajratish (`dom` kengaytmasi) |
| `app/Services/Site/AdminPanel/AdminPanelClient.php` | panelga kirish, sessiya, forma yuborish |
| `app/Console/Commands/InspectSiteForm.php` | forma maydonlarini o'qish (`site:form`) |
| `app/Console/Commands/InspectSiteDatabase.php` | sayt sxemasini o'qish (`site:inspect`) |
| `app/Console/Commands/VerifySiteMapping.php` | moslikni tekshirish (`site:verify`) |
| `app/Jobs/*` | navbat vazifalari: ingest → download → translate → publish |
| `app/Http/Controllers/ModerationController.php` | moderatsiya paneli |

Testlar: `php artisan test` (82 ta test). Kod uslubi: `vendor/bin/pint`.

---

## Hali qilinmagan / keyingi qadamlar

* `api` usulini tanlasangiz, qabul qiluvchi endpoint institut saytida
  yozilishi kerak. `database` usulida sayt tomonida hech narsa kerak emas,
  lekin `config/site.php` dagi moslik `site:inspect` natijasiga qarab
  to'ldiriladi.
* Kanaldagi post **tahrirlansa** (`edited_channel_post`) hozir yangi tarjima
  ketmaydi — allaqachon chop etilgan postni yangilash mantiqi qo'shilmagan.
* Postni kanaldan o'chirish saytdagi nusxani o'chirmaydi.
* Rasmlar lokal diskda saqlanadi; S3 kerak bo'lsa `FILESYSTEM_DISK` orqali.
* Moderatorga yangi post kelgani haqida xabar (Telegram/email) yuborilmaydi.
