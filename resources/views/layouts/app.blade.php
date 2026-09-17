<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Yangiliklar moderatsiyasi')</title>
    <style>
        :root {
            --bg: #f6f7f9; --card: #fff; --line: #e3e6ea; --text: #1c2024;
            --muted: #6b7280; --accent: #2f6feb; --ok: #17803d; --bad: #b42318; --warn: #b54708;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #14161a; --card: #1c1f24; --line: #2c3037; --text: #e7e9ec;
                --muted: #9aa1ab; --accent: #6b9dff; --ok: #4ade80; --bad: #f87171; --warn: #fbbf24;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text);
               font: 15px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        a { color: var(--accent); }
        header { background: var(--card); border-bottom: 1px solid var(--line); }
        .wrap { max-width: 1040px; margin: 0 auto; padding: 0 16px; }
        header .wrap { display: flex; align-items: center; gap: 16px; height: 56px; }
        header .brand { font-weight: 600; text-decoration: none; color: var(--text); }
        header nav { margin-left: auto; display: flex; gap: 12px; align-items: center; }
        main { padding: 24px 0 64px; }
        .card { background: var(--card); border: 1px solid var(--line);
                border-radius: 10px; padding: 16px; margin-bottom: 14px; }
        .flash { border-left: 3px solid var(--ok); }
        .flash.error { border-left-color: var(--bad); }
        .muted { color: var(--muted); }
        .row { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        .badge { font-size: 12px; padding: 2px 8px; border-radius: 99px;
                 border: 1px solid var(--line); white-space: nowrap; }
        .badge-ok { color: var(--ok); border-color: currentColor; }
        .badge-bad { color: var(--bad); border-color: currentColor; }
        .badge-review { color: var(--warn); border-color: currentColor; }
        .badge-muted { color: var(--muted); }
        label { display: block; font-size: 13px; color: var(--muted); margin: 10px 0 4px; }
        input[type=text], input[type=email], input[type=password], textarea, select {
            width: 100%; padding: 9px 11px; border: 1px solid var(--line); border-radius: 7px;
            background: var(--bg); color: var(--text); font: inherit;
        }
        textarea { min-height: 150px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; }
        button, .btn {
            display: inline-block; padding: 8px 14px; border-radius: 7px; border: 1px solid var(--line);
            background: var(--card); color: var(--text); font: inherit; cursor: pointer; text-decoration: none;
        }
        button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        button.danger { color: var(--bad); }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: start; padding: 10px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
        .thumbs { display: flex; gap: 8px; flex-wrap: wrap; }
        .thumb { border: 2px solid transparent; border-radius: 8px; overflow: hidden; width: 116px; }
        .thumb.cover { border-color: var(--accent); }
        .thumb img { display: block; width: 100%; height: 84px; object-fit: cover; }
        .thumb span { display: block; padding: 4px 6px; font-size: 11px; color: var(--muted); }
        .errors { color: var(--bad); font-size: 13px; margin: 8px 0; }
        .preview { border: 1px solid var(--line); border-radius: 7px; padding: 10px 12px;
                   margin-top: 6px; background: var(--bg); }
        .preview :first-child { margin-top: 0; }
        .preview :last-child { margin-bottom: 0; }
        @media (max-width: 640px) { header .wrap { height: auto; padding: 10px 16px; flex-wrap: wrap; } }
    </style>
</head>
<body>
<header>
    <div class="wrap">
        <a class="brand" href="{{ route('moderation.index') }}">Institut yangiliklari</a>
        @auth
            <nav>
                <span class="muted">{{ auth()->user()->name }}</span>
                <form method="post" action="{{ route('logout') }}">@csrf<button>Chiqish</button></form>
            </nav>
        @endauth
    </div>
</header>
<main class="wrap">
    @if (session('status'))
        <div class="card flash">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="card flash error">{{ session('error') }}</div>
    @endif
    @yield('content')
</main>
</body>
</html>
