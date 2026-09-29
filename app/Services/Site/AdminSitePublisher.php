<?php

namespace App\Services\Site;

use App\Contracts\SitePublisher;
use App\Models\Post;
use App\Models\PostMedia;
use App\Services\Site\AdminPanel\AdminPanelClient;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Postni saytning admin paneli orqali joylaydi.
 *
 * Saytda API yo'q va uning kodiga o'zgartirish kiritilmaydi — shuning uchun
 * odam qiladigan ish bajariladi: panelga kirish, "yangilik qo'shish"
 * formasini to'ldirish, yuborish.
 *
 * Bu usul uchtasining eng mo'rti: panel HTML'i o'zgarsa ishlamay qoladi.
 * Shu sababli maydon nomlari sozlamada turadi va `site:form` buyrug'i
 * ularni panelning o'zidan o'qib beradi.
 */
class AdminSitePublisher implements SitePublisher
{
    public function __construct(private readonly AdminPanelClient $client) {}

    public function name(): string
    {
        return 'admin';
    }

    public function externalId(Post $post): string
    {
        return "tg:{$post->chat_id}:{$post->message_id}";
    }

    /**
     * @return array{id: ?string, url: ?string}
     */
    public function publish(Post $post): array
    {
        $post->loadMissing(['translations', 'media']);

        if ($post->translations->isEmpty()) {
            throw new RuntimeException("Postda birorta tarjima yo'q.");
        }

        $config = (array) config('site.admin');
        $form = $this->client->form($config['create_path'], $config['action_hint'] ?? null);

        $values = array_merge(
            $this->textValues($post, $config, $form->fieldNames()),
            (array) ($config['extra']['common'] ?? []),
            (array) ($config['extra'][$post->type->value] ?? []),
        );

        $response = $this->client->submit($form, $values, $this->files($post, $config));

        return [
            'id' => null,
            'url' => $this->resolveUrl($response->effectiveUri()?->__toString()),
        ];
    }

    /**
     * Tarjimalarni forma maydonlariga joylaydi.
     *
     * Maydon nomi shabloni `{locale}` ni o'z ichiga olsa, har bir til uchun
     * alohida maydon to'ldiriladi. Shablonda `{locale}` bo'lmasa panel bir
     * tilli — faqat manba tili yuboriladi.
     *
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $available
     * @return array<string, string>
     */
    private function textValues(Post $post, array $config, array $available): array
    {
        $values = [];

        foreach ($config['fields'] as $field => $template) {
            if (blank($template)) {
                continue;
            }

            $multilingual = Str::contains($template, '{locale}');

            $translations = $multilingual
                ? $post->translations
                : $post->translations->where('locale', $post->source_locale)->whenEmpty(
                    fn () => $post->translations->take(1),
                );

            foreach ($translations as $translation) {
                $name = str_replace('{locale}', $translation->locale, $template);

                if (! in_array($name, $available, true)) {
                    throw new RuntimeException(
                        "Formada '{$name}' maydoni yo'q. Mavjud maydonlar: ".implode(', ', $available)
                        ."\nconfig/site.php -> admin.fields ni `php artisan site:form` natijasiga qarab tuzating."
                    );
                }

                $values[$name] = (string) $this->fieldValue($translation, $field);
            }
        }

        return $values;
    }

    private function fieldValue(object $translation, string $field): ?string
    {
        return match ($field) {
            'title' => $translation->title,
            'summary' => $translation->summary,
            'body' => $translation->body,
            default => null,
        };
    }

    /**
     * Rasmlar formaning fayl maydonlariga biriktiriladi. Muqova birinchi
     * o'rinda turadi.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, array{field: string, filename: string, contents: string, mime: ?string}>
     */
    private function files(Post $post, array $config): array
    {
        $coverField = $config['image_field'] ?? null;
        $galleryField = $config['gallery_field'] ?? null;

        if (blank($coverField) && blank($galleryField)) {
            return [];
        }

        $ordered = $post->media
            ->filter(fn (PostMedia $media) => $media->isDownloaded())
            ->sortByDesc(fn (PostMedia $media) => $media->is_cover ? 1 : 0)
            ->values();

        $files = [];

        foreach ($ordered as $index => $media) {
            $contents = $media->contents();

            if ($contents === null) {
                continue;
            }

            $field = $index === 0 && filled($coverField) ? $coverField : $galleryField;

            if (blank($field)) {
                continue;
            }

            $files[] = [
                'field' => $field,
                'filename' => $media->filename(),
                'contents' => $contents,
                'mime' => $media->mime,
            ];
        }

        return $files;
    }

    /**
     * Yuborilgandan keyin panel odatda yangilik sahifasiga yoki ro'yxatga
     * yo'naltiradi. O'sha manzil moderatsiya panelida havola bo'lib turadi.
     */
    private function resolveUrl(?string $effectiveUri): ?string
    {
        $template = config('site.admin.url_template');

        if (filled($template)) {
            return (string) $template;
        }

        return $effectiveUri;
    }
}
