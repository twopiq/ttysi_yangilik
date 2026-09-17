@extends('layouts.app')
@section('title', 'Postlar')

@section('content')
    <div class="card">
        <div class="row">
            <strong>Holat bo'yicha:</strong>
            <a class="btn" href="{{ route('moderation.index') }}">Hammasi</a>
            @foreach (\App\Enums\PostStatus::cases() as $case)
                <a class="btn" href="{{ route('moderation.index', ['status' => $case->value]) }}"
                   @if ($status === $case->value) style="border-color: var(--accent)" @endif>
                    {{ $case->label() }} ({{ $counts[$case->value] ?? 0 }})
                </a>
            @endforeach
        </div>
    </div>

    <div class="card">
        <table>
            <thead>
            <tr>
                <th>#</th>
                <th>Sarlavha</th>
                <th>Tur</th>
                <th>Holat</th>
                <th>Kanalda</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($posts as $post)
                @php $primary = $post->translation($post->source_locale) ?? $post->translations->first(); @endphp
                <tr>
                    <td class="muted">{{ $post->id }}</td>
                    <td>
                        <a href="{{ route('moderation.edit', $post) }}">
                            {{ $primary?->title ?? \Illuminate\Support\Str::limit($post->source_text, 70) ?: '(matnsiz)' }}
                        </a>
                        <div class="muted" style="font-size:12px">
                            {{ $post->translations->pluck('locale')->implode(', ') ?: 'tarjima yo\'q' }}
                            @if ($post->media->isNotEmpty()) · {{ $post->media->count() }} fayl @endif
                        </div>
                        @if ($post->last_error)
                            <div class="errors">{{ \Illuminate\Support\Str::limit($post->last_error, 160) }}</div>
                        @endif
                    </td>
                    <td>{{ $post->type->label() }}</td>
                    <td><span class="badge {{ $post->status->badgeClass() }}">{{ $post->status->label() }}</span></td>
                    <td class="muted">{{ optional($post->posted_at)->format('d.m.Y H:i') }}</td>
                    <td>
                        @if ($post->published_url)
                            <a class="btn" href="{{ $post->published_url }}" target="_blank" rel="noopener">Saytda</a>
                        @elseif ($post->telegram_url)
                            <a class="btn" href="{{ $post->telegram_url }}" target="_blank" rel="noopener">Kanalda</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Hozircha post yo'q.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $posts->links() }}
@endsection
