@extends('layouts.app')
@section('title', 'Naikkan Produk Shopee')
@section('heading', 'Naikkan Produk Otomatis — Shopee')
@section('content')
<div class="mx-auto max-w-[1440px] space-y-5 px-1 sm:px-2">
    @if(session('status'))<div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">Periksa input yang dimasukkan.</div>@endif

    <div class="bg-white rounded-2xl border border-stone-200 p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-xl">
                <h2 class="text-sm font-bold text-stone-800">Naikkan Produk Otomatis</h2>
                <p class="mt-1 text-xs text-stone-500">Fitur resmi Shopee: maksimal <b>{{ $maks }} produk</b> bisa dinaikkan ke urutan atas, tiap kali naik berlaku <b>4 jam</b>. Selama saklar <b>AKTIF</b>, sistem mengecek tiap <b>10 menit</b> dan langsung menaikkan lagi produk yang masa naiknya habis — 24 jam, tanpa perlu klik manual di Shopee.</p>
            </div>
            <span class="px-3 py-1.5 rounded-full text-xs font-semibold {{ $aktif ? 'bg-emerald-50 text-emerald-700' : 'bg-stone-100 text-stone-500' }}">{{ $aktif ? '● AKTIF' : '○ MATI' }}</span>
        </div>
        @unless($terhubung)
            <p class="mt-3 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">Toko Shopee belum terhubung — hubungkan dulu di menu Integrasi → Shopee.</p>
        @endunless
        <div class="mt-4 flex flex-wrap gap-2">
            <form method="POST" action="{{ route('shopee-naikkan.aktif') }}">@csrf
                <input type="hidden" name="aktif" value="{{ $aktif ? 0 : 1 }}">
                <button class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold rounded-lg {{ $aktif ? 'bg-stone-100 text-stone-700 hover:bg-stone-200' : 'bg-emerald-700 text-white hover:bg-emerald-800' }}">{{ $aktif ? 'Matikan' : 'Aktifkan' }}</button>
            </form>
            <form method="POST" action="{{ route('shopee-naikkan.jalankan') }}">@csrf
                <button class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200" title="Satu putaran sekarang (walau saklar mati) — untuk uji coba"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19V5m-6 6 6-6 6 6"/></svg>Jalankan sekarang</button>
            </form>
            <a href="{{ route('marketplace-stock.channel', 'shopee') }}" class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>Stok &amp; Harga Shopee</a>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden shadow-sm">
        <div class="px-5 py-3 border-b border-stone-100 flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-bold text-stone-800">Produk yang dinaikkan</h3>
            <span class="text-xs text-stone-500">{{ $dipilih->count() }}/{{ $maks }} terpakai</span>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-stone-50 text-xs text-stone-500 border-b border-stone-200">
                <tr>
                    <th class="px-4 py-2 text-left font-medium">Produk</th>
                    <th class="px-4 py-2 text-left font-medium">Status</th>
                    <th class="px-4 py-2 text-left font-medium">Terakhir naik</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @forelse($dipilih as $it)
                <tr class="align-top">
                    <td class="px-4 py-3">
                        <div class="font-medium text-stone-800 leading-snug line-clamp-2" title="{{ $it->title }}">{{ $it->title }}</div>
                        <div class="mt-0.5 text-[11px] text-stone-400 font-mono">Item {{ $it->item_id }}</div>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        @if($it->sedangNaik())
                            @php $menit = (int) now()->diffInMinutes($it->boosted_until); @endphp
                            <span data-status-naik class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold bg-emerald-50 text-emerald-700">Sedang naik · sisa {{ intdiv($menit, 60) }}j {{ $menit % 60 }}m</span>
                        @elseif($it->last_status === 'failed')
                            <span data-status-naik class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold bg-rose-50 text-rose-700">Gagal</span>
                            <div class="mt-1 text-[11px] text-rose-600 whitespace-normal break-words max-w-[260px]" title="{{ $it->last_error }}">{{ \Illuminate\Support\Str::limit($it->last_error, 120) }}</div>
                        @else
                            <span data-status-naik class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold bg-stone-100 text-stone-500">{{ $aktif ? 'Menunggu dinaikkan' : 'Saklar mati' }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-xs text-stone-600">{{ $it->last_boosted_at?->translatedFormat('d M Y H:i') ?? '—' }}</td>
                    <td class="px-4 py-3 text-right">
                        <form method="POST" action="{{ route('shopee-naikkan.hapus', $it) }}" onsubmit="return confirm('Hapus produk ini dari Naikkan Produk?')">@csrf @method('DELETE')
                            <button class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-white px-2.5 py-1.5 text-[11px] font-medium text-rose-700 hover:bg-rose-50">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-8 text-center text-stone-400 text-sm">Belum ada produk dipilih.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
        <div class="px-5 py-4 border-t border-stone-100">
            @if($dipilih->count() >= $maks)
                <p class="text-xs text-stone-500">Sudah {{ $maks }} produk (batas Shopee). Hapus salah satu untuk mengganti.</p>
            @elseif($kandidat->isEmpty())
                <p class="text-xs text-stone-500">Belum ada produk Shopee lain di listing — klik "Refresh listing" di halaman Stok &amp; Harga Shopee.</p>
            @else
                <form method="POST" action="{{ route('shopee-naikkan.tambah') }}" class="flex flex-wrap items-center gap-2">@csrf
                    <select name="item_id" required aria-label="Pilih produk Shopee" class="min-w-0 flex-1 px-3 py-2 text-sm border border-stone-300 rounded-lg">
                        <option value="">Pilih produk Shopee…</option>
                        @foreach($kandidat as $itemId => $judul)<option value="{{ $itemId }}">{{ $judul }}</option>@endforeach
                    </select>
                    <button class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold bg-red-700 text-white rounded-lg hover:bg-red-800">+ Tambah</button>
                </form>
            @endif
        </div>
    </div>
</div>
@endsection
