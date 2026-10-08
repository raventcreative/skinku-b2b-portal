<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="min-h-screen">
    <header class="bg-ink-900 text-white">
        <div class="mx-auto max-w-7xl px-4 h-14 flex items-center justify-between gap-4">
            <a href="{{ route('dashboard') }}" class="font-bold tracking-tight flex items-center gap-2">
                <span class="grid size-7 place-items-center rounded-lg bg-brand-600 text-xs">JA</span>
                {{ config('app.name') }}
            </a>
            <nav class="flex items-center gap-1 text-sm">
                <a href="{{ route('dashboard') }}" class="px-3 py-1.5 rounded-lg hover:bg-white/10 {{ request()->routeIs('dashboard') ? 'bg-white/15' : '' }}">Dashboard</a>
                <a href="{{ route('clients.index') }}" class="px-3 py-1.5 rounded-lg hover:bg-white/10 {{ request()->routeIs('clients.index') ? 'bg-white/15' : '' }}">Klien</a>
                <span class="mx-2 text-ink-400 text-xs hidden sm:inline">{{ auth()->user()->name }}{{ auth()->user()->isAdmin() ? ' · admin' : '' }}</span>
                <form method="POST" action="{{ route('logout') }}">@csrf
                    <button class="px-3 py-1.5 rounded-lg hover:bg-white/10 cursor-pointer">Keluar</button>
                </form>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-6">
        @include('partials.client-nav', ['client' => $client ?? null])
        @include('partials.flash')
        @yield('content')
    </main>

    <footer class="mx-auto max-w-7xl px-4 py-8 text-xs text-ink-400">
        {{ config('app.name') }} — pencatatan jurnal berbantuan AI. Hasil baca AI selalu wajib direview sebelum masuk buku.
    </footer>
</div>
</body>
</html>
