@extends('layouts.app')
@section('title', "Post #{$post->id}")

@section('content')
    <div class="card">
        <div class="row">
            <span class="badge {{ $post->status->badgeClass() }}">{{ $post->status->label() }}</span>
            <span class="badge">{{ $post->type->label() }}</span>
            <span class="muted">Kanalda: {{ optional($post->posted_at)->format('d.m.Y H:i') ?? '—' }}</span>
            @if ($post->telegram_url)
                <a href="{{ $post->telegram_url }}" target="_blank" rel="noopener">Asl post</a>
            @endif
            @if ($post->published_url)
                <a href="{{ $post->published_url }}" target="_blank" rel="noopener">Saytdagi versiya</a>
            @endif
        </div>

        @if ($post->last_error)
            <div class="errors">{{ $post->last_error }}</div>
        @endif

        <label>Kanaldagi asl matn</label>
        <div class="preview">{!! $post->source_html !!}</div>
    </div>

    @if ($post->media->isNotEmpty())
        <div class="card">
            <label>Fayllar — muqovani tanlang</label>
            <div class="thumbs">
                @foreach ($post->media as $item)
                    <label class="thumb {{ $item->is_cover ? 'cover' : '' }}">
                        @if ($item->isDownloaded() && str_starts_with((string) $item->mime, 'image/'))
                            <img src="{{ route('moderation.media', $item) }}" alt="">
                        @else
                            <span style="display:block;height:84px;padding:8px">{{ $item->kind }}</span>
                        @endif
                        <span>
                            <input form="post-form" type="radio" name="cover_media_id"
                                   value="{{ $item->id }}" @checked($item->is_cover)>
                            {{ $item->is_cover ? 'muqova' : 'muqova qil' }}
                        </span>
                        @if ($item->download_error)
                            <span class="errors" style="font-size:11px">{{ \Illuminate\Support\Str::limit($item->download_error, 60) }}</span>
                        @endif
                    </label>
                @endforeach
            </div>
        </div>
    @endif

    <form id="post-form" method="post" action="{{ route('moderation.update', $post) }}">
        @csrf
        @method('PUT')

        @forelse ($post->translations as $translation)
            <div class="card">
                <div class="row">
                    <strong>{{ $translation->localeName() }} ({{ $translation->locale }})</strong>
                    @if ($translation->edited_by_human)
                        <span class="badge">qo'lda tahrirlangan</span>
                    @endif
                </div>

                <label>Sarlavha</label>
                <input type="text" name="translations[{{ $translation->locale }}][title]"
                       value="{{ old("translations.{$translation->locale}.title", $translation->title) }}" required>

                <label>Qisqacha mazmun</label>
                <input type="text" name="translations[{{ $translation->locale }}][summary]"
                       value="{{ old("translations.{$translation->locale}.summary", $translation->summary) }}">

                <label>Matn (HTML)</label>
                <textarea name="translations[{{ $translation->locale }}][body]" required>{{ old("translations.{$translation->locale}.body", $translation->body) }}</textarea>

                <label>Ko'rinishi</label>
                <div class="preview">{!! $translation->body !!}</div>
            </div>
        @empty
            <div class="card muted">Tarjima hali tayyor emas.</div>
        @endforelse

        @if ($errors->any())
            <div class="card flash error">
                @foreach ($errors->all() as $error)<div class="errors">{{ $error }}</div>@endforeach
            </div>
        @endif

        <div class="card row">
            <button class="primary" type="submit">Saqlash</button>
        </div>
    </form>

    <div class="card row">
        <form method="post" action="{{ route('moderation.approve', $post) }}">
            @csrf
            <button class="primary"
                    onclick="return confirm('Post saytda chop etiladi. Davom etamizmi?')">
                Tasdiqlash va saytga joylash
            </button>
        </form>
        <form method="post" action="{{ route('moderation.retranslate', $post) }}">
            @csrf<button>Qayta tarjima qilish</button>
        </form>
        <form method="post" action="{{ route('moderation.reject', $post) }}">
            @csrf
            <button class="danger" onclick="return confirm('Post rad etilsinmi?')">Rad etish</button>
        </form>
    </div>
@endsection
