<?php

namespace App\Services\Site;

use App\Contracts\SitePublisher;
use App\Models\Post;
use App\Models\PostMedia;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Postni institut sayti bazasiga to'g'ridan-to'g'ri yozadi.
 *
 * Ustun nomlari `config/site.database` da moslanadi, shuning uchun sayt
 * sxemasi o'zgarsa kod emas, sozlama tuzatiladi. Takroriy nashr yangi
 * yozuv yaratmaydi: birinchi nashrda olingan id bizning `posts.remote_id`
 * da saqlanadi va keyingisida o'sha qator yangilanadi — saytda maxsus
 * `external_id` ustuni talab qilinmaydi.
 */
class DatabaseSitePublisher implements SitePublisher
{
    private const LAYOUT_TRANSLATIONS = 'translations';

    private const LAYOUT_JSON = 'json';

    private const LAYOUT_SUFFIXED = 'columns';

    public function name(): string
    {
        return 'database';
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

        $config = $this->config();
        $storedPaths = $this->copyMediaToSite($post);
        $slug = $this->slug($post);

        $id = $this->connection()->transaction(function () use ($post, $config, $slug, $storedPaths): string {
            $id = $this->writeMainRow($post, $config, $slug, $storedPaths);

            if ($config['layout'] === self::LAYOUT_TRANSLATIONS) {
                $this->writeTranslationRows($post, $config, $id);
            }

            $this->writeMediaRows($config, $id, $storedPaths);

            return $id;
        });

        return ['id' => $id, 'url' => $this->url($id, $slug)];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $storedPaths
     */
    private function writeMainRow(Post $post, array $config, string $slug, array $storedPaths): string
    {
        $row = array_merge(
            $config['defaults']['common'] ?? [],
            $config['defaults'][$post->type->value] ?? [],
        );

        $columns = $config['columns'];

        $this->put($row, $columns['slug'] ?? null, $slug);
        $this->put($row, $columns['published_at'] ?? null, ($post->posted_at ?? now())->toDateTimeString());
        $this->put($row, $columns['updated_at'] ?? null, now()->toDateTimeString());
        $this->put($row, $columns['source_url'] ?? null, $post->telegram_url);
        $this->put($row, $columns['cover'] ?? null, $storedPaths[0] ?? null);

        if ($config['layout'] === self::LAYOUT_JSON) {
            $row += $this->jsonColumns($post, $config);
        }

        if ($config['layout'] === self::LAYOUT_SUFFIXED) {
            $row += $this->suffixedColumns($post, $config);
        }

        $table = $this->connection()->table($config['table']);

        if (filled($post->remote_id) && $this->rowExists($config, $post->remote_id)) {
            $table->where($config['primary_key'], $post->remote_id)->update($row);

            return (string) $post->remote_id;
        }

        $this->put($row, $columns['created_at'] ?? null, now()->toDateTimeString());

        return (string) $this->connection()
            ->table($config['table'])
            ->insertGetId($row, $config['primary_key']);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function writeTranslationRows(Post $post, array $config, string $id): void
    {
        $settings = $config['translations'];
        $columns = $settings['columns'];

        foreach ($post->translations as $translation) {
            $values = [];
            $this->put($values, $columns['title'] ?? null, $translation->title);
            $this->put($values, $columns['summary'] ?? null, $translation->summary);
            $this->put($values, $columns['body'] ?? null, $translation->body);

            $this->connection()->table($settings['table'])->updateOrInsert(
                [
                    $settings['foreign_key'] => $id,
                    $settings['locale_column'] => $translation->locale,
                ],
                $values,
            );
        }
    }

    /**
     * Rasm qatorlari har nashrda qaytadan yoziladi — moderator muqovani
     * almashtirgan yoki rasmni olib tashlagan bo'lishi mumkin.
     *
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $storedPaths
     */
    private function writeMediaRows(array $config, string $id, array $storedPaths): void
    {
        $settings = $config['media'];

        if (blank($settings['table'] ?? null) || $storedPaths === []) {
            return;
        }

        $this->connection()->table($settings['table'])
            ->where($settings['foreign_key'], $id)
            ->delete();

        $rows = [];
        foreach ($storedPaths as $position => $path) {
            $row = [$settings['foreign_key'] => $id, $settings['path_column'] => $path];
            $this->put($row, $settings['position_column'] ?? null, $position);
            $rows[] = $row;
        }

        $this->connection()->table($settings['table'])->insert($rows);
    }

    /**
     * Fayllarni saytning storage papkasiga ko'chiradi.
     *
     * Muqova birinchi o'rinda turadi, chunki sayt odatda birinchi rasmni
     * asosiy deb oladi.
     *
     * @return array<int, string> bazaga yoziladigan nisbiy yo'llar
     */
    private function copyMediaToSite(Post $post): array
    {
        $storage = $this->config()['storage'];
        $root = $storage['path'] ?? null;

        if (blank($root)) {
            return [];
        }

        $directory = rtrim($root, '/').'/'.trim((string) $storage['subdirectory'], '/');

        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Saytning rasm papkasini yaratib bo'lmadi: {$directory}");
        }

        if (! is_writable($directory)) {
            throw new RuntimeException("Saytning rasm papkasiga yozib bo'lmaydi: {$directory}");
        }

        $ordered = $post->media
            ->filter(fn (PostMedia $media) => $media->isDownloaded())
            ->sortByDesc(fn (PostMedia $media) => $media->is_cover ? 1 : 0)
            ->values();

        $paths = [];

        foreach ($ordered as $media) {
            $name = $media->filename();
            $contents = $media->contents();

            if ($contents === null) {
                continue;
            }

            if (file_put_contents("{$directory}/{$name}", $contents) === false) {
                throw new RuntimeException("Rasmni saqlab bo'lmadi: {$directory}/{$name}");
            }

            $paths[] = trim((string) $storage['stored_prefix'], '/').'/'.$name;
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private function jsonColumns(Post $post, array $config): array
    {
        $values = [];

        foreach ($config['json'] as $field => $column) {
            if (blank($column)) {
                continue;
            }

            $values[$column] = json_encode(
                $post->translations->mapWithKeys(fn ($translation) => [
                    $translation->locale => $this->fieldValue($translation, $field),
                ])->all(),
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, ?string>
     */
    private function suffixedColumns(Post $post, array $config): array
    {
        $values = [];

        foreach ($post->translations as $translation) {
            foreach ($config['suffixed'] as $field => $template) {
                if (blank($template)) {
                    continue;
                }

                $column = str_replace(
                    ['{locale}', '{LOCALE}'],
                    [$translation->locale, Str::upper($translation->locale)],
                    $template,
                );

                $values[$column] = $this->fieldValue($translation, $field);
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
     * Slug manba tilidagi sarlavhadan yasaladi. Saytda shunday slug bo'lsa,
     * oxiriga raqam qo'shiladi.
     */
    private function slug(Post $post): string
    {
        $config = $this->config();
        $column = $config['columns']['slug'] ?? null;

        $source = $post->translation($post->source_locale) ?? $post->translations->first();
        $base = Str::slug((string) $source?->title) ?: 'yangilik';
        $base = Str::limit($base, 180, '');

        if (blank($column)) {
            return $base;
        }

        $slug = $base;
        $suffix = 1;

        while ($this->slugTaken($config, $column, $slug, $post->remote_id)) {
            $slug = "{$base}-".++$suffix;
        }

        return $slug;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function slugTaken(array $config, string $column, string $slug, ?string $remoteId): bool
    {
        return $this->connection()
            ->table($config['table'])
            ->where($column, $slug)
            ->when(filled($remoteId), fn ($query) => $query->where($config['primary_key'], '!=', $remoteId))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function rowExists(array $config, string $remoteId): bool
    {
        return $this->connection()
            ->table($config['table'])
            ->where($config['primary_key'], $remoteId)
            ->exists();
    }

    private function url(string $id, string $slug): ?string
    {
        $template = $this->config()['url_template'] ?? null;

        return blank($template) ? null : str_replace(['{id}', '{slug}'], [$id, $slug], $template);
    }

    /**
     * Qiymatni faqat ustun nomi berilgan bo'lsa qo'shadi.
     *
     * @param  array<string, mixed>  $row
     */
    private function put(array &$row, ?string $column, mixed $value): void
    {
        if (filled($column)) {
            $row[$column] = $value;
        }
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return (array) config('site.database');
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection($this->config()['connection']);
    }
}
