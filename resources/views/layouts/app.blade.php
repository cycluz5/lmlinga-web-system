<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', config('app.name', 'LMLinga'))</title>

    {{-- Cropped tight to the cross artwork (logo.png has wide padding that shrinks badly at favicon size). --}}
    <link rel="icon" type="image/png" href="{{ asset('assets/images/logo/favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('assets/images/logo/favicon.png') }}">

  {{-- Google Fonts: Poppins (app) + Protest Riot (branding) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&family=Protest+Riot&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('styles')
</head>
<body class="lml-page">
    <a href="#main-content" class="lml-skip-link">Skip to main content</a>

    @yield('body')

    @stack('scripts')
</body>
</html>
