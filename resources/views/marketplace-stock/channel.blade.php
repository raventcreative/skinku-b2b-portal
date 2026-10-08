@extends('layouts.app')
@php $namaCh = ['tiktok' => 'TikTok', 'shopee' => 'Shopee'][$channel] ?? ucfirst($channel); @endphp
@section('title', 'Stok & Harga '.$namaCh)
@section('heading', 'Stok & Harga '.$namaCh)
@section('content')
@php
    $rp = fn ($v) => $v === null ? '—' : 'Rp'.number_format((float) $v, 0, ',', '.');
    $angka = fn ($v) => $v === null ? '—' : number_format((int) $v, 0, ',', '.');
    $ikonSimpan = '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M5 4h12l3 3v13H4V4h1Zm3 0v6h8V4M8 20v-7h8v7"/></svg>';
@endphp
@include('partials.rupiah-input')
<div class="mx-auto max-w-[1440px] space-y-5 px-1 sm:px-2">
    @if(session('status'))<div class="px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">Periksa input yang dimasukkan.</div>@endif

    <div class="bg-white rounded-2xl border border-stone-200 p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
            <div>
                <h2 class="text-sm font-bold text-stone-800">Stok &amp; Harga {{ $namaCh }}</h2>
                <p class="mt-1 text-xs text-stone-500">Ketik angka di kolom Override lalu tekan <b>Enter</b> atau tombol simpan — tersimpan tanpa reload &amp; langsung dikirim ke {{ $namaCh }} (Enter juga memindah kursor ke produk berikutnya). Kosongkan = kembali ikut master. Angka <b>efektif</b> = yang dikirim: override bila diisi, kalau kosong ikut master.</p>
            </div>
            <span class="px-3 py-1.5 rounded-full bg-stone-100 text-stone-600 text-xs font-semibold">{{ count($rows) }} produk</span>
        </div>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('marketplace-stock.push-all') }}">@csrf<button class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold bg-emerald-700 text-white rounded-lg hover:bg-emerald-800"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7v5h-5M4 17v-5h5m-3-3a7 7 0 0 1 12-2l2 2M4 13l2 2a7 7 0 0 0 12-2"/></svg>Sinkron semua</button></form>
            <form method="POST" action="{{ route('marketplace-stock.resolve') }}">@csrf<button class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12a9 9 0 1 0 2.6-6.4L3 8m0-5v5h5m4 1v5l3 2"/></svg>Refresh listing</button></form>
            <a href="{{ route('marketplace-stock.index') }}" class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/></svg>Produk master</a>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden shadow-sm">
        @if(count($rows))
            <div class="overflow-x-auto">
            <table class="w-full min-w-[900px] text-sm">
                <thead class="bg-stone-50 text-xs text-stone-500 border-b border-stone-200">
                    <tr>
                        <th rowspan="2" class="px-4 py-2 text-left font-medium align-bottom">Produk</th>
                        <th colspan="2" class="px-4 pt-2 text-center font-semibold text-stone-700 border-l border-stone-200">Stok</th>
                        <th colspan="2" class="px-4 pt-2 text-center font-semibold text-stone-700 border-l border-stone-200">Harga</th>
                        <th rowspan="2" class="px-4 py-2 text-left font-medium align-bottom border-l border-stone-200">Status kirim</th>
                    </tr>
                    <tr>
                        <th class="px-4 py-2 text-right font-medium border-l border-stone-200">Efektif</th>
                        <th class="px-4 py-2 text-left font-medium">Override {{ $namaCh }}</th>
                        <th class="px-4 py-2 text-right font-medium border-l border-stone-200">Efektif</th>
                        <th class="px-4 py-2 text-left font-medium">Override {{ $namaCh }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                @foreach($rows as $row)
                    @php
                        $m = $row['master'];
                        $img = $m->imageUrl();
                        $urlOverride = route('marketplace-stock.override', ['channel' => $channel, 'master' => $m]);
                    @endphp
                    <tr class="align-top hover:bg-stone-50">
                        <td class="px-4 py-3">
                            <div class="flex items-start gap-3 min-w-[220px] max-w-md">
                                @if($img)
                                    <img src="{{ $img }}" alt="" class="w-10 h-10 shrink-0 rounded-lg object-cover border border-stone-200">
                                @else
                                    <div class="w-10 h-10 shrink-0 rounded-lg bg-stone-100 border border-stone-200 flex items-center justify-center text-stone-300 text-[10px]">no img</div>
                                @endif
                                <div class="min-w-0">
                                    <div class="font-medium text-stone-800 leading-snug line-clamp-2" title="{{ $m->name }}">{{ $m->name }}</div>
                                    <div class="mt-0.5 text-[11px] text-stone-400 font-mono">{{ $m->master_sku }}</div>
                                </div>
                            </div>
                        </td>

                        <td class="px-4 py-3 text-right whitespace-nowrap border-l border-stone-100">
                            <div data-efektif="stock" class="font-semibold text-stone-800 tabular-nums">{{ $angka($row['eff_stock']) }}</div>
                            <div data-tanda-override="stock" class="mt-0.5 text-[11px] font-semibold text-amber-700 {{ $row['override_stock'] === null ? 'hidden' : '' }}">override</div>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{-- Form = cadangan tanpa JS (Enter → simpan biasa); dengan JS tersimpan otomatis lewat data-url. --}}
                            <form method="POST" action="{{ route('marketplace-stock.channel.stok', ['channel'=>$channel,'master'=>$m]) }}" class="flex items-center gap-1.5">@csrf
                                <input type="number" name="quantity" min="0" step="1" value="{{ $row['override_stock'] }}" placeholder="ikut master" data-override="stock" data-url="{{ $urlOverride }}" aria-label="Override stok {{ $namaCh }} {{ $m->name }}" class="h-8 w-24 px-2 text-xs text-right tabular-nums border border-stone-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-200">
                                <button type="button" data-simpan aria-label="Simpan override stok {{ $m->name }}" title="Simpan" class="inline-flex items-center justify-center w-8 h-8 shrink-0 rounded-lg bg-white border border-stone-300 text-stone-600 hover:bg-stone-100 hover:text-stone-800">{!! $ikonSimpan !!}</button>
                            </form>
                            <span data-status-simpan class="block mt-0.5 text-[11px] whitespace-normal"></span>
                        </td>

                        <td class="px-4 py-3 text-right whitespace-nowrap border-l border-stone-100">
                            <div data-efektif="price" class="font-semibold text-stone-800 tabular-nums">{{ $rp($row['eff_price']) }}</div>
                            <div data-tanda-override="price" class="mt-0.5 text-[11px] font-semibold text-amber-700 {{ $row['override_price'] === null ? 'hidden' : '' }}">override</div>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <form method="POST" action="{{ route('marketplace-stock.channel.harga', ['channel'=>$channel,'master'=>$m]) }}" class="flex items-center gap-1.5">@csrf
                                <input type="text" inputmode="numeric" data-rupiah name="price" value="{{ \App\Support\Rupiah::input($row['override_price']) }}" placeholder="ikut master" data-override="price" data-url="{{ $urlOverride }}" aria-label="Override harga {{ $namaCh }} {{ $m->name }}" class="h-8 w-28 px-2 text-xs text-right tabular-nums border border-stone-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-200">
                                <button type="button" data-simpan aria-label="Simpan override harga {{ $m->name }}" title="Simpan" class="inline-flex items-center justify-center w-8 h-8 shrink-0 rounded-lg bg-white border border-stone-300 text-stone-600 hover:bg-stone-100 hover:text-stone-800">{!! $ikonSimpan !!}</button>
                            </form>
                            <span data-status-simpan class="block mt-0.5 text-[11px] whitespace-normal"></span>
                        </td>

                        <td class="px-4 py-3 border-l border-stone-100" data-status-kirim>@include('marketplace-stock._status-kirim', ['lst' => $row['listing']])</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @else
            <p class="px-5 py-12 text-center text-stone-400 text-sm">Belum ada master di channel ini.</p>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
// Override diisi langsung di tabel: tersimpan tanpa reload & langsung dikirim ke marketplace saat pindah kolom, Enter
// (lanjut ke produk berikutnya di kolom yang sama, ala spreadsheet) atau tombol simpan (utk HP — Enter sulit).
// Kosong = kembali ikut master.
(function () {
    const WARNA = {proses: 'text-stone-400', ok: 'text-emerald-700', gagal: 'text-rose-600'};
    document.querySelectorAll('[data-override]').forEach(inp => {
        const field = inp.dataset.override;
        const kolom = [...document.querySelectorAll('[data-override="' + field + '"]')];
        const tr = inp.closest('tr');
        const td = inp.closest('td');
        const status = td.querySelector('[data-status-simpan]');
        const tulis = (jenis, teks) => { status.className = 'block mt-0.5 text-[11px] whitespace-normal ' + WARNA[jenis]; status.textContent = teks; status.title = teks; };
        let tersimpan = inp.value; // angka terakhir yang sudah tersimpan
        let dikirim = null;        // angka yang sedang dikirim — cegah simpan dobel (tap tombol di HP = kolom lepas fokus + klik)
        inp.addEventListener('keydown', e => {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            const berikut = kolom[kolom.indexOf(inp) + 1];
            if (berikut) { berikut.focus(); berikut.select(); } else { inp.blur(); }
        });
        const simpan = async () => {
            if (inp.value === tersimpan || inp.value === dikirim) return;
            dikirim = inp.value;
            tulis('proses', 'menyimpan…');
            try {
                const res = await fetch(inp.dataset.url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': window.CSRF},
                    body: JSON.stringify({field: field, value: inp.value}),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || res.redirected) throw new Error(data.message || 'Gagal menyimpan');
                tersimpan = inp.value = data.override ?? '';
                tr.querySelector('[data-efektif="' + field + '"]').textContent = data.efektif;
                tr.querySelector('[data-tanda-override="' + field + '"]').classList.toggle('hidden', data.override === null);
                tr.querySelector('[data-status-kirim]').innerHTML = data.status;
                tulis('ok', '✓ tersimpan');
            } catch (e) {
                inp.value = tersimpan;
                tulis('gagal', '✗ ' + e.message);
            } finally {
                dikirim = null;
            }
        };
        inp.addEventListener('change', simpan);
        td.querySelector('[data-simpan]').addEventListener('click', simpan);
    });
})();
</script>
@endpush
