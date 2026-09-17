<?php

namespace App\Http\Controllers;

use App\Enums\PostStatus;
use App\Jobs\PublishPost;
use App\Jobs\TranslatePost;
use App\Models\Post;
use App\Models\PostMedia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ModerationController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');

        $posts = Post::query()
            ->with(['translations', 'media'])
            ->when(
                $status !== null && PostStatus::tryFrom($status) !== null,
                fn ($query) => $query->where('status', $status),
                fn ($query) => $query->whereNotIn('status', [PostStatus::Rejected->value]),
            )
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $counts = Post::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('moderation.index', compact('posts', 'counts', 'status'));
    }

    public function edit(Post $post): View
    {
        $post->load(['translations', 'media']);

        return view('moderation.edit', compact('post'));
    }

    public function update(Request $request, Post $post): RedirectResponse
    {
        $data = $request->validate([
            'translations' => ['required', 'array'],
            'translations.*.title' => ['required', 'string', 'max:250'],
            'translations.*.summary' => ['nullable', 'string', 'max:490'],
            'translations.*.body' => ['required', 'string'],
            'cover_media_id' => ['nullable', 'integer'],
        ]);

        foreach ($data['translations'] as $locale => $fields) {
            $translation = $post->translations()->where('locale', $locale)->first();

            if ($translation === null) {
                continue;
            }

            // Bo'sh maydonlar null bo'lib ham, '' bo'lib ham kelishi mumkin —
            // shuning uchun solishtirishdan oldin bir ko'rinishga keltiramiz.
            $summary = (string) ($fields['summary'] ?? '');

            $changed = $translation->title !== $fields['title']
                || $translation->body !== $fields['body']
                || (string) $translation->summary !== $summary;

            $translation->forceFill([
                'title' => $fields['title'],
                'summary' => $summary === '' ? null : $summary,
                'body' => $fields['body'],
                // Bir marta qo'lda tahrirlangan matnni qayta tarjima bosmaydi.
                'edited_by_human' => $translation->edited_by_human || $changed,
            ])->save();
        }

        if (! empty($data['cover_media_id'])) {
            $this->setCover($post, (int) $data['cover_media_id']);
        }

        return redirect()
            ->route('moderation.edit', $post)
            ->with('status', 'Saqlandi.');
    }

    public function approve(Post $post): RedirectResponse
    {
        if ($post->translations()->count() === 0) {
            return back()->with('error', "Tarjimasiz postni tasdiqlab bo'lmaydi.");
        }

        $post->forceFill([
            'status' => PostStatus::Approved,
            'approved_at' => now(),
            'approved_by' => auth()->id(),
            'last_error' => null,
        ])->save();

        PublishPost::dispatch($post->id);

        return redirect()
            ->route('moderation.index')
            ->with('status', "Tasdiqlandi va nashrga yuborildi: {$post->id}");
    }

    public function reject(Post $post): RedirectResponse
    {
        $post->forceFill(['status' => PostStatus::Rejected])->save();

        return redirect()
            ->route('moderation.index')
            ->with('status', "Post rad etildi: {$post->id}");
    }

    public function retranslate(Post $post): RedirectResponse
    {
        $post->forceFill([
            'status' => PostStatus::Translating,
            'last_error' => null,
        ])->save();

        TranslatePost::dispatch($post->id);

        return back()->with('status', "Qayta tarjima navbatga qo'yildi.");
    }

    /** Rasmni brauzerda ko'rsatish (fayllar public papkada emas). */
    public function media(PostMedia $media): Response
    {
        abort_unless($media->isDownloaded(), 404);

        return response(Storage::disk('local')->get($media->path), 200, [
            'Content-Type' => $media->mime ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function setCover(Post $post, int $mediaId): void
    {
        if (! $post->media()->whereKey($mediaId)->exists()) {
            return;
        }

        $post->media()->update(['is_cover' => false]);
        $post->media()->whereKey($mediaId)->update(['is_cover' => true]);
    }
}
