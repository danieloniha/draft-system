<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name', 'PickTurn') }}</title>
    <link rel="stylesheet" href="{{ asset('/assets/style.css') }}">
</head>
<body>
    {{-- The log in / sign up / password pages: the site's own card, not the stock Breeze one. --}}
    <main class="form-container auth-container">
        <a class="auth-brand" href="{{ url('/') }}">PickTurn</a>

        {{ $slot }}
    </main>
</body>
</html>
