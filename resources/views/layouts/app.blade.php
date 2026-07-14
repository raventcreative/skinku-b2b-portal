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
                if (!function_exists('navItem')) {
                    function navItem($route, $label, $active) {
                        $is = request()->routeIs($active);
                        $cls = $is ? 'bg-red-900 text-white border-l-4 border-white pl-3' : 'text-red-100 hover:text-white hover:bg-red-900/50 pl-4';
                        return '<a href="'.route($route).'" class="flex items-center gap-3 pr-4 py-2.5 rounded-lg '.$cls.'">'.$label.'</a>';
                    }
                }
            @endphp

            {!! navItem('dashboard', 'Dashboard', 'dashboard') !!}

            {{-- Menu visibility follows the configurable role permissions. --}}
            @if($u->canDo('create_po'))
                {!! navItem('purchase-orders.create', 'Buat PO', 'purchase-orders.create') !!}
            @endif

            {!! navItem('purchase-orders.index', $u->isPartner() ? 'Riwayat PO' : 'Purchase Orders', 'purchase-orders.index') !!}

            @php
                // Staff yang mengelola produk/stok/produksi → tampilkan grup accordion "Manajemen Produk".
                $isProdukManager = $u->canDo('manage_products') || $u->canDo('manage_production') || $u->canDo('manage_hq_stock');
                $produkGroupOpen = request()->routeIs('products.index') || request()->routeIs('inventory.index')
                    || request()->routeIs('materials.*') || request()->routeIs('productions.*') || request()->routeIs('stock-movements.index');
            @endphp

            @if($isProdukManager)
                <button type="button" onclick="toggleNavGroup('grpProduk')"
                    class="w-full flex items-center justify-between gap-3 pr-4 pl-4 py-2.5 rounded-lg text-red-100 hover:text-white hover:bg-red-900/50 {{ $produkGroupOpen ? 'text-white' : '' }}">
                    <span>Manajemen Produk</span>
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
                        {!! navItem('stock-movements.index', 'Stock Movement', 'stock-movements.index') !!}
                    @endif
                </div>
            @else
                {{-- Partner: cukup "Stok Saya" datar (bukan manajer produk). --}}
                {!! navItem('inventory.index', 'Stok Saya', 'inventory.index') !!}
            @endif

            {{-- "Stok Masuk (beli jadi)" disembunyikan atas permintaan (SKINKU selalu produksi/repack sendiri).
                 Kode, route, & tabel tetap ada — untuk memunculkan lagi, aktifkan blok di bawah ini.
            @if($u->canDo('receive_stock'))
                {!! navItem('stock-receipts.index', 'Stok Masuk (beli jadi)', 'stock-receipts.*') !!}
            @endif
            --}}

            @if($u->canDo('view_reports'))
                {!! navItem('reports.index', $u->isPartner() ? 'Laporan Pembelian' : 'Laporan Penjualan', 'reports.index') !!}
            @endif

            @if($u->canDo('view_accounting'))
                {!! navItem('accounting.index', 'Akuntansi', 'accounting.*') !!}
            @endif

            @if($u->canDo('manage_tiktok'))
                {!! navItem('tiktok.index', 'Integrasi TikTok', 'tiktok.*') !!}
            @endif

            @if($u->canDo('view_learning'))
                {!! navItem('learning.index', 'Pembelajaran', 'learning.*') !!}
            @endif

            @if($u->canDo('manage_users'))
                {!! navItem('users.index', 'Kelola Anggota', 'users.index') !!}
            @endif

            @if($u->canDo('view_audit_log'))
                {!! navItem('audit-logs.index', 'Audit Log', 'audit-logs.index') !!}
            @endif

            @if($u->canDo('manage_production'))
                {!! navItem('suppliers.index', 'Supplier', 'suppliers.*') !!}
            @endif

            @if($u->canDo('system_settings'))
                {!! navItem('settings.index', 'Pengaturan Sistem', 'settings.index') !!}
            @endif

            @if($u->canDo('manage_permissions'))
                {!! navItem('permissions.index', 'Manajemen Hak Akses', 'permissions.index') !!}
            @endif
        </nav>

        <div class="p-3 border-t border-red-900/50 space-y-1">
            <a href="{{ route('account.password') }}" class="block px-4 py-2 text-[11px] text-red-100 hover:text-white rounded-lg hover:bg-red-900/50">Ubah Password</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="w-full text-left px-4 py-2 text-[11px] font-semibold text-white hover:bg-red-900/60 rounded-lg">Keluar Sistem</button>
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
