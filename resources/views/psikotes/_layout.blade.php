<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/png" href="{{ asset('img/favicon.png') }}?v=2">
    <title>@yield('title', 'Psikotes') · SKINKU</title>
    @vite('resources/css/app.css')
</head>
{{-- Halaman psikotes kandidat (publik, tanpa login). --}}
<body class="min-h-full bg-stone-100 text-stone-800">
    <div class="max-w-3xl mx-auto px-4 py-6 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <p class="text-xl font-bold tracking-tight text-red-800">SKINKU<span class="text-2xl">.</span></p>
            <span class="text-xs text-stone-500">Psikotes rekrutmen</span>
        </div>
        @yield('body')
        <p class="text-[11px] text-stone-400 text-center">Jawaban hanya dipakai tim HR SKINKU untuk proses rekrutmen dan tidak dibagikan ke pihak lain.</p>
    </div>
    @stack('scripts')
</body>
</html>
