@extends('layouts.app')
@section('title', 'Kirish')

@section('content')
    <div class="card" style="max-width: 380px; margin: 40px auto;">
        <h2 style="margin-top:0">Moderator kirishi</h2>
        <form method="post" action="{{ route('login') }}">
            @csrf
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>

            <label for="password">Parol</label>
            <input id="password" type="password" name="password" required>

            <label style="display:flex;gap:8px;align-items:center;margin-top:12px">
                <input type="checkbox" name="remember" value="1" style="width:auto"> Meni eslab qol
            </label>

            @if ($errors->any())
                <div class="errors">{{ $errors->first() }}</div>
            @endif

            <button class="primary" style="margin-top:14px;width:100%">Kirish</button>
        </form>
    </div>
@endsection
