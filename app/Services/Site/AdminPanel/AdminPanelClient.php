<?php

namespace App\Services\Site\AdminPanel;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Saytning admin paneliga brauzer kabi kiradi va formalarni to'ldiradi.
 *
 * Saytda API bo'lmasa va uning kodiga tegish imkoni bo'lmasa, qoladigan yo'l
 * shu: odam qiladigan ishni — kirish, forma to'ldirish, yuborish — kod
 * bajaradi. Sessiya cookie bitta savatda saqlanadi, Laravel'ning `_token`
 * qiymati esa formaning o'zidan olinadi.
 */
class AdminPanelClient
{
    private ?CookieJar $jar = null;

    private bool $loggedIn = false;

    public function __construct(private readonly FormParser $parser) {}

    /**
     * Panelga kiradi. Muvaffaqiyat shu bilan tekshiriladi: kirishdan keyin
     * himoyalangan sahifada parol maydoni qolmasligi kerak.
     */
    public function login(): void
    {
        if ($this->loggedIn) {
            return;
        }

        $config = $this->config();
        $loginUrl = $this->url($config['login_path']);

        $page = $this->request()->get($loginUrl);

        if (! $page->successful()) {
            throw new RuntimeException("Kirish sahifasi ochilmadi (HTTP {$page->status()}): {$loginUrl}");
        }

        $form = $this->parser->pickLogin($page->body(), $loginUrl);

        if ($form === null) {
            throw new RuntimeException("Kirish sahifasida parol maydoni topilmadi: {$loginUrl}");
        }

        $values = array_merge($form->carriedValues(), [
            $config['login_fields']['username'] => $config['username'],
            $config['login_fields']['password'] => $config['password'],
        ]);

        $response = $this->request()->asForm()->post($form->action, $values);

        if ($response->status() >= 500) {
            throw new RuntimeException("Kirishda sayt xatosi (HTTP {$response->status()}).");
        }

        if ($this->looksLikeLoginPage($response->body(), $form->action)) {
            throw new RuntimeException(
                'Kirish amalga oshmadi — login yoki parol qabul qilinmadi. '
                .'SITE_ADMIN_USERNAME / SITE_ADMIN_PASSWORD va login_fields nomlarini tekshiring.'
            );
        }

        $this->loggedIn = true;
    }

    /** Himoyalangan sahifani oladi. */
    public function page(string $path): Response
    {
        $this->login();

        $url = $this->url($path);
        $response = $this->request()->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Sahifa ochilmadi (HTTP {$response->status()}): {$url}");
        }

        if ($this->looksLikeLoginPage($response->body(), $url)) {
            throw new RuntimeException("Sessiya tushib qoldi — sahifa qayta kirish so'rayapti: {$url}");
        }

        return $response;
    }

    /** Sahifadagi formani ajratib beradi. */
    public function form(string $path, ?string $actionHint = null): HtmlForm
    {
        $url = $this->url($path);
        $response = $this->page($path);

        $form = $this->parser->pick($this->parser->forms($response->body(), $url), $actionHint);

        if ($form === null) {
            throw new RuntimeException("Sahifada forma topilmadi: {$url}");
        }

        return $form;
    }

    /**
     * Formani to'ldirib yuboradi.
     *
     * @param  array<string, string|null>  $values
     * @param  array<int, array{field: string, filename: string, contents: string, mime: ?string}>  $files
     */
    public function submit(HtmlForm $form, array $values, array $files = []): Response
    {
        $this->login();

        $payload = array_merge($form->carriedValues(), array_filter(
            $values,
            fn (?string $value) => $value !== null,
        ));

        $request = $this->request();

        if ($files === []) {
            $response = $request->asForm()->post($form->action, $payload);
        } else {
            foreach ($files as $file) {
                $request = $request->attach(
                    $file['field'],
                    $file['contents'],
                    $file['filename'],
                    filled($file['mime']) ? ['Content-Type' => $file['mime']] : [],
                );
            }

            $response = $request->asMultipart()->post($form->action, $payload);
        }

        if ($response->status() >= 400) {
            throw new RuntimeException(
                "Forma qabul qilinmadi (HTTP {$response->status()}). ".$this->explainErrors($response->body())
            );
        }

        return $response;
    }

    /**
     * Laravel validatsiya xatolarini javob sahifasidan izlaydi, shunda xato
     * sababi logda ko'rinadi.
     */
    private function explainErrors(string $html): string
    {
        if (preg_match_all('/<(?:li|p|span|div)[^>]*(?:invalid-feedback|error|text-danger)[^>]*>(.*?)<\//is', $html, $matches)) {
            $messages = array_slice(array_filter(array_map(
                fn (string $raw) => trim(html_entity_decode(strip_tags($raw))),
                $matches[1],
            )), 0, 5);

            if ($messages !== []) {
                return 'Sayt aytgan xatolar: '.implode(' | ', $messages);
            }
        }

        return 'Sayt xato sababini bermadi.';
    }

    /**
     * Sahifa qayta kirish so'rayaptimi. Kirish sahifasining o'zi bo'lsa
     * (login POST natijasi) parol maydoni qolgan-qolmagani tekshiriladi.
     */
    private function looksLikeLoginPage(string $html, string $url): bool
    {
        $form = $this->parser->pickLogin($html, $url);

        if ($form === null) {
            return false;
        }

        // Ba'zi panellar har sahifada "qayta kirish" modalini saqlaydi —
        // parol maydoni bo'lsa ham, chiqish havolasi bor bo'lsa kirgan holat.
        return ! Str::contains(Str::lower($html), ['logout', 'chiqish', 'выход', 'sign out']);
    }

    private function url(string $path): string
    {
        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return rtrim((string) $this->config()['base_url'], '/').'/'.ltrim($path, '/');
    }

    private function request(): PendingRequest
    {
        $config = $this->config();

        if (blank($config['base_url'])) {
            throw new RuntimeException('SITE_ADMIN_URL sozlanmagan.');
        }

        $this->jar ??= new CookieJar;

        return Http::timeout((int) $config['timeout'])
            ->withOptions(['cookies' => $this->jar, 'allow_redirects' => ['max' => 10]])
            ->withHeaders(['User-Agent' => (string) $config['user_agent']]);
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return (array) config('site.admin');
    }
}
