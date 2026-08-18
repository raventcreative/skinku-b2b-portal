<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/glightbox/dist/css/glightbox.min.css">
    <script src="https://cdn.jsdelivr.net/npm/glightbox/dist/js/glightbox.min.js"></script>
    <script>
        tailwind.config = {
            theme: { extend: { colors: {
                brand: { dark: '#1c1917', gold: '#c8a96a', emerald: '#0f4c3a', cream: '#faf7f2' }
            }}}
        };
    </script>
    @stack('head')
</head>
<body class="h-full bg-stone-100 text-stone-800 antialiased">

{{-- Banner samaran: menempel di ATAS segalanya dan sticky, supaya mustahil lupa
     sedang jadi orang lain. Lupa = PO uji coba masuk ke akun mitra sungguhan
     atas nama mereka. --}}
@isset($impersonator)
@if($impersonator)
    <div class="sticky top-0 z-50 bg-amber-400 text-amber-950 px-4 py-2 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-sm font-semibold shadow">
        <span>⚠ Anda sedang masuk sebagai <b>{{ auth()->user()?->fullname }}</b> ({{ auth()->user()?->role }}) — bukan akun Anda.</span>
        <form method="POST" action="{{ route('impersonate.stop') }}">
            @csrf
            <button type="submit" class="px-3 py-1 rounded-lg bg-amber-950 text-amber-50 hover:bg-amber-900 text-xs">
                Kembali ke akun saya ({{ $impersonator->fullname }})
            </button>
        </form>
    </div>
@endif
@endisset
@php
    $u = auth()->user();
    $isStaff = $u->isStaff();
    $isManagement = $u->isManagement();
@endphp

<div class="min-h-full flex">
    {{-- Mobile overlay (behind sidebar, above content) --}}
    <div id="sidebarOverlay" onclick="closeSidebar()" class="hidden fixed inset-0 bg-black/50 z-30 lg:hidden"></div>

    {{-- Sidebar --}}
    <aside id="sidebar" class="w-64 bg-red-800 text-red-50 flex flex-col fixed inset-y-0 left-0 z-40 -translate-x-full lg:translate-x-0 transition-transform duration-200 ease-out">
        <div class="p-6 border-b border-red-900/50 relative">
            <button onclick="closeSidebar()" class="lg:hidden absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-lg text-red-100 hover:bg-red-900/60 text-lg" aria-label="Tutup menu">✕</button>
            <h1 class="text-2xl font-bold tracking-tight text-white">SKINKU<span class="text-white text-3xl leading-none">.</span></h1>
            <p class="text-[10px] uppercase tracking-widest text-red-200 font-semibold mt-1">B2B Distributor Portal</p>
        </div>

        <div class="px-5 py-4 border-b border-red-900/50">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-white flex items-center justify-center font-bold text-red-700 uppercase text-xs">
                    {{ strtoupper(mb_substr($u->displayName(), 0, 2)) }}
                </div>
                <div class="min-w-0">
                    <p class="text-xs font-bold text-white truncate">{{ $u->displayName() }}</p>
                    <p class="text-[10px] text-red-200 truncate">{{ $u->email }}</p>
                </div>
            </div>
            <div class="mt-3 flex items-center gap-2">
                <span class="px-2 py-0.5 text-[9px] rounded font-bold uppercase bg-white/20 text-white">{{ str_replace('_', ' ', $u->role) }}</span>
                @if($u->company_name)<span class="text-[9.5px] text-red-200 truncate max-w-[110px]">{{ $u->company_name }}</span>@endif
            </div>
        </div>

        <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto text-xs font-semibold">
            @php
                // Icon menu = inline SVG (Heroicons outline, zero-dep). Dipetakan dari NAMA ROUTE
                // supaya semua pemanggilan navItem tak perlu diubah. currentColor → ikut warna teks menu.
                if (!function_exists('navIcon')) {
                    function navIcon($key) {
                        $p = match ($key) {
                            'dashboard' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z"/>',
                            'purchase-orders.create' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>',
                            'purchase-orders.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z"/>',
                            'jaringan-saya.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/>',
                            'commissions.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3"/>',
                            'products.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9"/>',
                            'inventory.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>',
                            'materials.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 0-6.23-.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0 1 12 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5"/>',
                            'productions.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>',
                            'stok-opname.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0 1 18 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3 1.5 1.5 3-3.75"/>',
                            'hq-stock.report' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M9 16.5v.75m3-3v3M15 12v5.25m-4.5-15H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>',
                            'backdated-sales.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/>',
                            'reports.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6"/>',
                            'reports.omzet-mitra' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6a7.5 7.5 0 1 0 7.5 7.5h-7.5V6Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5H21A7.5 7.5 0 0 0 13.5 3v7.5Z"/>',
                            'reports.komisi' => '<path stroke-linecap="round" stroke-linejoin="round" d="m9 14.25 6-6m4.5-3.493V21.75l-3.75-1.5-3.75 1.5-3.75-1.5-3.75 1.5V4.757c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0 1 11.186 0c1.1.128 1.907 1.077 1.907 2.185ZM9.75 9h.008v.008H9.75V9Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm4.125 4.5h.008v.008h-.008V13.5Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/>',
                            'accounting.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 15.75V18m-7.5-6.75h.008v.008H8.25v-.008Zm0 2.25h.008v.008H8.25V13.5Zm0 2.25h.008v.008H8.25v-.008Zm0 2.25h.008v.008H8.25V18Zm2.498-6.75h.007v.008h-.007v-.008Zm0 2.25h.007v.008h-.007V13.5Zm0 2.25h.007v.008h-.007v-.008Zm0 2.25h.007v.008h-.007V18Zm2.504-6.75h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V13.5Zm0 2.25h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V18Zm2.498-6.75h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V13.5ZM8.25 6h7.5v2.25h-7.5V6ZM12 2.25c-1.892 0-3.758.11-5.593.322C5.307 2.7 4.5 3.65 4.5 4.757V19.5a2.25 2.25 0 0 0 2.25 2.25h10.5a2.25 2.25 0 0 0 2.25-2.25V4.757c0-1.108-.806-2.057-1.907-2.185A48.507 48.507 0 0 0 12 2.25Z"/>',
                            'withdrawals.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z"/>',
                            'tiktok.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="m9 9 10.5-3m0 6.553v3.75a2.25 2.25 0 0 1-1.632 2.163l-1.32.377a1.803 1.803 0 1 1-.99-3.467l2.31-.66a2.25 2.25 0 0 0 1.632-2.163Zm0 0V2.25L9 5.25v10.303m0 0v3.75a2.25 2.25 0 0 1-1.632 2.163l-1.32.377a1.803 1.803 0 0 1-.99-3.467l2.31-.66A2.25 2.25 0 0 0 9 15.553Z"/>',
                            'learning.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5"/>',
                            'kols.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z"/>',
                            'okr.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 3v1.5M3 21v-6m0 0 2.77-.693a9 9 0 0 1 6.208.682l.108.054a9 9 0 0 0 6.086.71l3.114-.732a48.524 48.524 0 0 1-.005-10.499l-3.11.732a9 9 0 0 1-6.085-.711l-.108-.054a9 9 0 0 0-6.208-.682L3 4.5M3 15V4.5"/>',
                            'kanban.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 4.5v15m6-15v15m-10.875 0h15.75c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H4.125C3.504 4.5 3 5.004 3 5.625v12.75c0 .621.504 1.125 1.125 1.125Z"/>',
                            'mindmaps.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 0 0 1.5-.189m-1.5.189a6.01 6.01 0 0 1-1.5-.189m3.75 7.478a12.06 12.06 0 0 1-4.5 0m3.75 2.383a14.406 14.406 0 0 1-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 1 0-7.517 0c.85.493 1.509 1.333 1.509 2.316V18"/>',
                            'ai.knowledge' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z"/>',
                            'users.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z"/>',
                            'struktur-jaringan.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z"/>',
                            'audit-logs.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/>',
                            'suppliers.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/>',
                            'announcements.manage' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 0 8.835-2.535m0 0A23.74 23.74 0 0 0 18.795 3m.38 1.125a23.91 23.91 0 0 1 1.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 0 0 1.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 0 1 0 3.46"/>',
                            'permissions.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z"/>',
                            'settings.index' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75"/>',
                            'grp-produk' => '<path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/>',
                            'grp-kerja' => '<path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0M12 12.75h.008v.008H12v-.008Z"/>',
                            'grp-laporan' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M9 16.5v.75m3-3v3M15 12v5.25m-4.5-15H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>',
                            'rekening' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z"/>',
                            'password' => '<path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/>',
                            'logout' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/>',
                            default => '',
                        };

                        return $p === '' ? '' : '<svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">'.$p.'</svg>';
                    }
                }
                if (!function_exists('navItem')) {
                    function navItem($route, $label, $active) {
                        $is = request()->routeIs($active);
                        $cls = $is ? 'bg-red-900 text-white border-l-4 border-white pl-3' : 'text-red-100 hover:text-white hover:bg-red-900/50 pl-4';
                        return '<a href="'.route($route).'" class="flex items-center gap-3 pr-4 py-2.5 rounded-lg '.$cls.'">'.navIcon($route).'<span>'.$label.'</span></a>';
                    }
                }
            @endphp

            {!! navItem('dashboard', 'Dashboard', 'dashboard') !!}

            {{-- Menu visibility follows the configurable role permissions. --}}
            @if($u->canDo('create_po'))
                {!! navItem('purchase-orders.create', 'Buat PO', 'purchase-orders.create') !!}
            @endif

            {!! navItem('purchase-orders.index', $u->isPartner() ? 'Riwayat PO' : 'Purchase Orders', 'purchase-orders.index') !!}

            @if($u->isPartner() && $u->downlines()->exists())
                {!! navItem('jaringan-saya.index', 'Jaringan Saya', 'jaringan-saya.index') !!}
            @endif

            @if($u->isPartner())
                {!! navItem('commissions.index', 'Saldo Komisi', 'commissions.*') !!}
            @endif

            @php
                // Staff yang mengelola produk/stok/produksi → tampilkan grup accordion "Manajemen Produk".
                $isProdukManager = $u->canDo('manage_products') || $u->canDo('manage_production') || $u->canDo('manage_hq_stock');
                $produkGroupOpen = request()->routeIs('products.index') || request()->routeIs('inventory.index')
                    || request()->routeIs('materials.*') || request()->routeIs('productions.*') || request()->routeIs('stock-movements.index')
                    || request()->routeIs('stok-opname.*') || request()->routeIs('hq-stock.*')
                    || request()->routeIs('backdated-sales.*');
            @endphp

            @if($isProdukManager)
                <button type="button" onclick="toggleNavGroup('grpProduk')"
                    class="w-full flex items-center justify-between gap-3 pr-4 pl-4 py-2.5 rounded-lg text-red-100 hover:text-white hover:bg-red-900/50 {{ $produkGroupOpen ? 'text-white' : '' }}">
                    <span class="flex items-center gap-3">{!! navIcon('grp-produk') !!}<span>Manajemen Produk</span></span>
                    <svg id="grpProdukChevron" class="w-3.5 h-3.5 transition-transform {{ $produkGroupOpen ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div id="grpProduk" class="{{ $produkGroupOpen ? '' : 'hidden' }} ml-4 pl-2 border-l border-red-900/50 space-y-1">
                    @if($u->canDo('manage_products'))
                        {!! navItem('products.index', 'Produk Master', 'products.index') !!}
                    @endif
                    {!! navItem('inventory.index', 'Pemantauan Stok', 'inventory.index') !!}
                    @if($u->canDo('manage_production'))
                        {!! navItem('materials.index', 'Bahan Baku', 'materials.*') !!}
                        {!! navItem('productions.index', 'Produksi (HPP)', 'productions.*') !!}
                    @endif
                    @if($u->canDo('manage_hq_stock'))
                        {{-- "Stock Movement" sengaja tidak di sidebar (No.3): diakses via drill-down
                             dari Laporan Stok HQ. Route tetap aktif (stock-movements.index). --}}
                        {!! navItem('stok-opname.index', 'Stok Opname', 'stok-opname.index') !!}
                        {!! navItem('hq-stock.report', 'Laporan Stok HQ', 'hq-stock.report') !!}
                        {!! navItem('backdated-sales.index', 'Penjualan Back-date', 'backdated-sales.*') !!}
                    @endif
                </div>
            @else
                {{-- Partner stockist (distributor/grand) lihat "Stok Saya"; reseller tak pegang
                     stok HQ (beli manual ke distributor) jadi menu ini disembunyikan. --}}
                @if(\App\Support\PartnerHierarchy::holdsStock($u->role))
                    {!! navItem('inventory.index', 'Stok Saya', 'inventory.index') !!}
                @endif
            @endif

            {{-- "Stok Masuk (beli jadi)" disembunyikan atas permintaan (SKINKU selalu produksi/repack sendiri).
                 Kode, route, & tabel tetap ada — untuk memunculkan lagi, aktifkan blok di bawah ini.
            @if($u->canDo('receive_stock'))
                {!! navItem('stock-receipts.index', 'Stok Masuk (beli jadi)', 'stock-receipts.*') !!}
            @endif
            --}}

            @php
                // Grup accordion "Laporan": Penjualan/Pembelian + Omzet Mitra + Komisi.
                $laporanGroupOpen = request()->routeIs('reports.index') || request()->routeIs('reports.omzet-mitra') || request()->routeIs('reports.komisi');
            @endphp
            @if($u->canDo('view_reports') || $u->canDo('view_commission_report'))
                <button type="button" onclick="toggleNavGroup('grpLaporan')"
                    class="w-full flex items-center justify-between gap-3 pr-4 pl-4 py-2.5 rounded-lg text-red-100 hover:text-white hover:bg-red-900/50 {{ $laporanGroupOpen ? 'text-white' : '' }}">
                    <span class="flex items-center gap-3">{!! navIcon('grp-laporan') !!}<span>Laporan</span></span>
                    <svg id="grpLaporanChevron" class="w-3.5 h-3.5 transition-transform {{ $laporanGroupOpen ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div id="grpLaporan" class="{{ $laporanGroupOpen ? '' : 'hidden' }} ml-4 pl-2 border-l border-red-900/50 space-y-1">
                    @if($u->canDo('view_reports'))
                        {!! navItem('reports.index', $u->isPartner() ? 'Laporan Pembelian' : 'Laporan Penjualan', 'reports.index') !!}
                        @if($u->isStaff())
                            {!! navItem('reports.omzet-mitra', 'Omzet Mitra', 'reports.omzet-mitra') !!}
                        @endif
                    @endif
                    @if($u->canDo('view_commission_report'))
                        {!! navItem('reports.komisi', 'Laporan Komisi', 'reports.komisi') !!}
                    @endif
                </div>
            @endif

            @if($u->canDo('view_accounting'))
                {!! navItem('accounting.index', 'Akuntansi', 'accounting.*') !!}
            @endif

            @if($u->canDo('process_withdrawal'))
                {!! navItem('withdrawals.index', 'Penarikan', 'withdrawals.*') !!}
            @endif

            @if($u->canDo('manage_tiktok'))
                {!! navItem('tiktok.index', 'Integrasi TikTok', 'tiktok.*') !!}
            @endif

            @if($u->canDo('view_learning'))
                {!! navItem('learning.index', 'SKINKU Academy', 'learning.*') !!}
            @endif

            @if($u->canDo('kol.view'))
                {{-- 'kol*' (bukan 'kols.*') supaya menu tetap menyala di halaman
                     kol-deals.* dan kol-screenings.* --}}
                {!! navItem('kols.index', 'KOL', 'kol*') !!}
            @endif

            @php
                // Grup accordion "Produktivitas": OKR + Kanban + Mindmaps.
                $kerjaGroupOpen = request()->routeIs('okr.*') || request()->routeIs('kanban.*') || request()->routeIs('mindmaps.*');
            @endphp
            @if($u->canDo('okr.view') || $u->canDo('kanban.view') || $u->canDo('mindmap.view'))
                <button type="button" onclick="toggleNavGroup('grpKerja')"
                    class="w-full flex items-center justify-between gap-3 pr-4 pl-4 py-2.5 rounded-lg text-red-100 hover:text-white hover:bg-red-900/50 {{ $kerjaGroupOpen ? 'text-white' : '' }}">
                    <span class="flex items-center gap-3">{!! navIcon('grp-kerja') !!}<span>Produktivitas</span></span>
                    <svg id="grpKerjaChevron" class="w-3.5 h-3.5 transition-transform {{ $kerjaGroupOpen ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div id="grpKerja" class="{{ $kerjaGroupOpen ? '' : 'hidden' }} ml-4 pl-2 border-l border-red-900/50 space-y-1">
                    @if($u->canDo('okr.view'))
                        {!! navItem('okr.index', 'OKR', 'okr.*') !!}
                    @endif
                    @if($u->canDo('kanban.view'))
                        {!! navItem('kanban.index', 'Kanban', 'kanban.*') !!}
                    @endif
                    @if($u->canDo('mindmap.view'))
                        {!! navItem('mindmaps.index', 'Mindmaps', 'mindmaps.*') !!}
                    @endif
                </div>
            @endif

            @if($u->canDo('use_ai_assistant') && $u->isStaff())
                {!! navItem('ai.knowledge', 'Pengetahuan AI', 'ai.knowledge') !!}
            @endif

            @if($u->canDo('manage_users'))
                {!! navItem('users.index', 'Kelola Anggota', 'users.index') !!}
                {!! navItem('struktur-jaringan.index', 'Struktur Jaringan', 'struktur-jaringan.index') !!}
            @endif

            @if($u->canDo('view_audit_log'))
                {!! navItem('audit-logs.index', 'Audit Log', 'audit-logs.index') !!}
            @endif

            @if($u->canDo('manage_production'))
                {!! navItem('suppliers.index', 'Supplier', 'suppliers.*') !!}
            @endif

            @if($u->canDo('manage_announcements'))
                {!! navItem('announcements.manage', 'Pengumuman', 'announcements.manage') !!}
            @endif

            @if($u->canDo('manage_permissions'))
                {!! navItem('permissions.index', 'Manajemen Hak Akses', 'permissions.index') !!}
            @endif

            {{-- Pengaturan Sistem sengaja di paling bawah nav (dekat Ubah Password):
                 jarang dibuka, jadi tak menuh-menuhi menu atas. --}}
            @if($u->canDo('system_settings'))
                {!! navItem('settings.index', 'Pengaturan Sistem', 'settings.index') !!}
            @endif
        </nav>

        <div class="p-3 border-t border-red-900/50 space-y-1">
            {{-- Tombol Komunitas WA (khusus role user; QR -> popup, tanpa QR -> link langsung). --}}
            @if(!empty($sidebarCommunity))
                @if($sidebarCommunity->qrUrl())
                    <button type="button" onclick="document.getElementById('communityQr').showModal()"
                        class="w-full flex items-center gap-2 px-4 py-2 mb-1 text-[11px] font-bold text-white bg-green-600 hover:bg-green-500 rounded-lg">
                        <svg viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 shrink-0"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.07-.15-.67-1.62-.92-2.22-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.49 0 1.47 1.07 2.89 1.22 3.09.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.69.62.71.23 1.36.2 1.87.12.57-.09 1.76-.72 2.01-1.41.25-.7.25-1.29.17-1.41-.07-.12-.27-.2-.57-.35zM12.05 21.5a9.4 9.4 0 01-4.79-1.31l-.34-.2-3.56.93.95-3.47-.22-.36a9.38 9.38 0 01-1.44-5.01c0-5.18 4.22-9.4 9.41-9.4 2.51 0 4.87.98 6.64 2.76a9.34 9.34 0 012.75 6.65c0 5.18-4.22 9.41-9.4 9.41zm5.5-14.91A7.9 7.9 0 0012.05 4.3c-4.35 0-7.89 3.54-7.9 7.89 0 1.49.42 2.94 1.2 4.19l.19.3-.8 2.9 2.98-.78.29.17a7.86 7.86 0 004 1.1c4.35 0 7.89-3.54 7.9-7.89a7.85 7.85 0 00-2.32-5.58z"/></svg>
                        {{ $sidebarCommunity->buttonLabel() }}
                    </button>
                    <dialog id="communityQr" class="rounded-2xl p-0 backdrop:bg-black/50 w-[20rem] max-w-[90vw]">
                        <div class="p-5 text-center">
                            <p class="text-sm font-bold text-stone-800 mb-3">{{ $sidebarCommunity->buttonLabel() }}</p>
                            <img src="{{ $sidebarCommunity->qrUrl() }}" alt="QR Komunitas" class="w-56 h-56 object-contain mx-auto rounded-lg border border-stone-200">
                            <p class="text-[11px] text-stone-500 mt-3">Scan QR di atas pakai HP, atau:</p>
                            <a href="{{ $sidebarCommunity->link }}" target="_blank" rel="noopener"
                                class="mt-2 inline-block w-full px-4 py-2 text-sm font-semibold text-white bg-green-600 hover:bg-green-700 rounded-lg">Buka WhatsApp</a>
                            <form method="dialog" class="mt-2"><button class="text-xs text-stone-400 hover:text-stone-600">Tutup</button></form>
                        </div>
                    </dialog>
                @else
                    <a href="{{ $sidebarCommunity->link }}" target="_blank" rel="noopener"
                        class="w-full flex items-center gap-2 px-4 py-2 mb-1 text-[11px] font-bold text-white bg-green-600 hover:bg-green-500 rounded-lg">
                        <svg viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 shrink-0"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.07-.15-.67-1.62-.92-2.22-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.49 0 1.47 1.07 2.89 1.22 3.09.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.69.62.71.23 1.36.2 1.87.12.57-.09 1.76-.72 2.01-1.41.25-.7.25-1.29.17-1.41-.07-.12-.27-.2-.57-.35zM12.05 21.5a9.4 9.4 0 01-4.79-1.31l-.34-.2-3.56.93.95-3.47-.22-.36a9.38 9.38 0 01-1.44-5.01c0-5.18 4.22-9.4 9.41-9.4 2.51 0 4.87.98 6.64 2.76a9.34 9.34 0 012.75 6.65c0 5.18-4.22 9.41-9.4 9.41zm5.5-14.91A7.9 7.9 0 0012.05 4.3c-4.35 0-7.89 3.54-7.9 7.89 0 1.49.42 2.94 1.2 4.19l.19.3-.8 2.9 2.98-.78.29.17a7.86 7.86 0 004 1.1c4.35 0 7.89-3.54 7.9-7.89a7.85 7.85 0 00-2.32-5.58z"/></svg>
                        {{ $sidebarCommunity->buttonLabel() }}
                    </a>
                @endif
            @endif
            @if($u->isPartner())
                <a href="{{ route('account.rekening') }}" class="flex items-center gap-3 px-4 py-2 text-[11px] text-red-100 hover:text-white rounded-lg hover:bg-red-900/50">{!! navIcon('rekening') !!}<span>Rekening</span></a>
            @endif
            <a href="{{ route('account.password') }}" class="flex items-center gap-3 px-4 py-2 text-[11px] text-red-100 hover:text-white rounded-lg hover:bg-red-900/50">{!! navIcon('password') !!}<span>Ubah Password</span></a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="w-full flex items-center gap-3 px-4 py-2 text-[11px] font-semibold text-white hover:bg-red-900/60 rounded-lg">{!! navIcon('logout') !!}<span>Keluar Sistem</span></button>
            </form>
        </div>
    </aside>

    {{-- Main --}}
    <div class="flex-1 lg:ml-64 flex flex-col min-h-screen w-full min-w-0">
        <header class="h-16 bg-white border-b border-stone-200 flex items-center justify-between px-4 sm:px-8 sticky top-0 z-20">
            <div class="flex items-center gap-3 min-w-0">
                <button onclick="openSidebar()" class="lg:hidden w-9 h-9 flex items-center justify-center rounded-lg border border-stone-200 text-stone-700 hover:bg-stone-100 shrink-0" aria-label="Buka menu">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <h2 class="text-sm font-bold text-stone-800 truncate">@yield('heading', 'Dashboard')</h2>
            </div>
            <div class="text-[11px] text-stone-400 font-mono hidden sm:block">{{ config('app.name') }}</div>
        </header>

        <main class="p-4 sm:p-8 flex-1">
            @if(session('status'))
                <div class="mb-5 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">{{ session('status') }}</div>
            @endif
            @if(session('error'))
                <div class="mb-5 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm">{{ session('error') }}</div>
            @endif
            @if($errors->any())
                <div class="mb-5 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm">
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </main>

        <footer class="py-4 border-t border-stone-200 bg-white/50 px-4 sm:px-8 text-[11px] text-stone-400 flex flex-col sm:flex-row gap-1 sm:justify-between">
            <span>&copy; {{ date('Y') }} SKINKU B2B Portal. Powered by SQL + Laravel.</span>
            <span>HQ Jakarta, Indonesia</span>
        </footer>
    </div>
</div>

@auth
    @if(auth()->user()->canDo('use_ai_assistant'))
        @include('partials.ai-widget')
    @endif
@endauth

<script>
    // Attach CSRF token to fetch requests by default.
    window.CSRF = document.querySelector('meta[name="csrf-token"]').content;
    // Simple modal toggle helper.
    function toggleModal(id) {
        const el = document.getElementById(id);
        if (el) el.classList.toggle('hidden');
    }
    // Mobile sidebar drawer.
    function openSidebar() {
        document.getElementById('sidebar').classList.remove('-translate-x-full');
        document.getElementById('sidebarOverlay').classList.remove('hidden');
    }
    function closeSidebar() {
        document.getElementById('sidebar').classList.add('-translate-x-full');
        document.getElementById('sidebarOverlay').classList.add('hidden');
    }
    // Collapsible sidebar groups (accordion).
    function toggleNavGroup(id) {
        const el = document.getElementById(id);
        const chev = document.getElementById(id + 'Chevron');
        if (!el) return;
        const hidden = el.classList.toggle('hidden');
        if (chev) chev.classList.toggle('rotate-180', !hidden);
    }
    // On mobile, tapping a menu link should close the drawer.
    document.querySelectorAll('#sidebar nav a').forEach(function (a) {
        a.addEventListener('click', function () {
            if (window.matchMedia('(max-width: 1023px)').matches) closeSidebar();
        });
    });
    // Clickable + swipeable product photo galleries.
    if (window.GLightbox) {
        window.skinkuLightbox = GLightbox({ selector: '.glightbox', loop: true, touchNavigation: true });
    }
</script>
@stack('scripts')
</body>
</html>
