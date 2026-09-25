<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="{{ asset('img/favicon.png') }}?v=2">
    <title>@yield('title') · {{ config('app.name') }}</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-stone-100 text-stone-800 antialiased">
    <main class="max-w-3xl mx-auto px-4 py-10">
        <p class="text-2xl font-bold tracking-tight text-red-800">SKINKU<span class="text-3xl">.</span></p>
        <h1 class="mt-4 text-3xl font-bold text-stone-900">@yield('title')</h1>
        <p class="mt-1 text-xs text-stone-500">Berlaku sejak / Effective: 25 September 2026</p>
        <article class="mt-6 bg-white rounded-2xl border border-stone-200 p-6 space-y-4 text-sm leading-relaxed [&_h2]:text-base [&_h2]:font-bold [&_h2]:text-stone-900 [&_h2]:pt-2 [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:space-y-1">
            @yield('body')
        </article>
        <p class="mt-6 text-xs text-stone-500">
            <a href="{{ route('legal.privacy') }}" class="underline">Kebijakan Privasi</a> ·
            <a href="{{ route('legal.terms') }}" class="underline">Syarat Layanan</a> ·
            Kontak: <a href="mailto:{{ config('content.legal_contact') }}" class="underline">{{ config('content.legal_contact') }}</a>
        </p>
    </main>
</body>
</html>
