@extends('layouts.app')
@section('title', 'Naikkan Produk Shopee')
@section('heading', 'Naikkan Produk Otomatis — Shopee')
@section('content')
{{-- Pesan sukses/gagal ditampilkan layout (tak diulang di sini). --}}
<div class="mx-auto max-w-[1440px] space-y-5 px-1 sm:px-2">

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
        @if($slotLain->isNotEmpty())
            {{-- Slot dibagi satu toko: yang dinaikkan Desty / manual di Seller Centre ikut memakai jatah 5. --}}
            <div class="mt-3 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                <p><b>{{ $slotLain->count() }} dari {{ $maks }} slot toko dipakai produk lain</b> (bukan pilihan di sini — mis. dari Desty atau dinaikkan manual di Seller Centre). Matikan Naikkan Produk di Desty supaya slotnya dipakai produk pilihan di bawah.</p>
                <ul class="mt-1 space-y-0.5">
                    @foreach($slotLain as $s)<li>• {{ \Illuminate\Support\Str::limit($s['judul'], 70) }} — sisa {{ intdiv($s['menit'], 60) }}j {{ $s['menit'] % 60 }}m</li>@endforeach
                </ul>
                @if($dicek)<p class="mt-1 text-amber-700">Dicek {{ $dicek->translatedFormat('d M H:i') }}.</p>@endif
            </div>
        @endif
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
                    <th class="px-4 py-2 text-left font-medium">Naik terakhir → berikutnya</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            @forelse($dipilih as $it)
                <tr class="align-top">
                    <td class="px-4 py-3">
                        <div class="flex items-start gap-3">
                            @if($foto[$it->item_id] ?? null)
                                <img src="{{ $foto[$it->item_id] }}" alt="" class="w-10 h-10 shrink-0 rounded-lg object-cover border border-stone-200">
                            @else
                                <div class="w-10 h-10 shrink-0 rounded-lg bg-stone-100 border border-stone-200 flex items-center justify-center text-stone-300 text-[10px]">no img</div>
                            @endif
                            <div class="min-w-0">
                                <div class="font-medium text-stone-800 leading-snug line-clamp-2" title="{{ $it->title }}">{{ $it->title }}</div>
                                <div class="mt-0.5 text-[11px] text-stone-400 font-mono">Item {{ $it->item_id }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        @if($it->sedangNaik())
                            @php $menit = (int) now()->diffInMinutes($it->boosted_until); @endphp
                            <span data-status-naik class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold bg-emerald-50 text-emerald-700">Sedang naik · sisa {{ intdiv($menit, 60) }}j {{ $menit % 60 }}m</span>
                        @elseif($it->last_status === 'penuh')
                            <span data-status-naik class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold bg-amber-100 text-amber-800">Menunggu slot kosong</span>
                            <div class="mt-1 text-[11px] text-amber-700 whitespace-normal break-words max-w-[260px]">{{ $it->last_error }}</div>
                        @elseif($it->last_status === 'failed')
                            <span data-status-naik class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold bg-rose-50 text-rose-700">Gagal</span>
                            <div class="mt-1 text-[11px] text-rose-600 whitespace-normal break-words max-w-[260px]" title="{{ $it->last_error }}">{{ \Illuminate\Support\Str::limit($it->last_error, 120) }}</div>
                        @else
                            <span data-status-naik class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold bg-stone-100 text-stone-500">{{ $aktif ? 'Menunggu putaran '.$putaranBerikut->format('H.i') : 'Saklar mati' }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-xs text-stone-600">
                        {{ $it->last_boosted_at?->translatedFormat('d M H:i') ?? '—' }}
                        @if($it->sedangNaik())<div class="mt-0.5 text-[11px] text-stone-400">berikutnya {{ $it->boosted_until->translatedFormat('d M H:i') }}</div>@endif
                    </td>
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
                <p class="text-xs font-semibold text-stone-600">Tambah produk ({{ $maks - $dipilih->count() }} slot lagi)</p>
                <input id="cariNaikkan" type="search" placeholder="Cari nama produk atau SKU" aria-label="Cari produk Shopee" oninput="naikkanCari(this.value)" class="mt-1 w-full px-3 py-2 text-sm border border-stone-300 rounded-lg">
                <div class="mt-2 max-h-72 overflow-y-auto divide-y divide-stone-100 border border-stone-200 rounded-lg">
                    @foreach($kandidat as $itemId => $k)
                        <form method="POST" action="{{ route('shopee-naikkan.tambah') }}" data-cari="{{ mb_strtolower($k['judul'].' '.$k['sku'].' '.$itemId) }}" class="flex items-center gap-3 px-3 py-2 hover:bg-stone-50">@csrf
                            <input type="hidden" name="item_id" value="{{ $itemId }}">
                            @if($foto[$itemId] ?? null)
                                <img src="{{ $foto[$itemId] }}" alt="" class="w-8 h-8 shrink-0 rounded-md object-cover border border-stone-200">
                            @else
                                <div class="w-8 h-8 shrink-0 rounded-md bg-stone-100 border border-stone-200"></div>
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="text-sm text-stone-800 truncate" title="{{ $k['judul'] }}">{{ $k['judul'] }}</div>
                                <div class="text-[11px] text-stone-400 font-mono truncate">{{ $k['sku'] ?: 'tanpa SKU' }} · Item {{ $itemId }}</div>
                            </div>
                            <button class="shrink-0 px-3 py-1.5 text-xs font-semibold bg-red-700 text-white rounded-lg hover:bg-red-800">+ Tambah</button>
                        </form>
                    @endforeach
                    <p data-cari-kosong class="px-3 py-3 text-xs text-stone-400" style="display:none">Tidak ada produk yang cocok.</p>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
// Cari produk Shopee untuk ditambahkan (nama / SKU / item id) — saring daftar tanpa reload.
function naikkanCari(q) {
    q = q.trim().toLowerCase();
    let ada = 0;
    document.querySelectorAll('[data-cari]').forEach(el => {
        const cocok = q === '' || el.dataset.cari.includes(q);
        el.style.display = cocok ? '' : 'none';
        if (cocok) ada++;
    });
    document.querySelector('[data-cari-kosong]').style.display = ada ? 'none' : '';
}
</script>
@endpush
