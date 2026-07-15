@extends('layouts.app')
@section('title', 'Stok Opname')
@section('heading', 'Stok Opname / Saldo Awal')

@section('content')
<div class="max-w-3xl">
    <div class="mb-4 px-4 py-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-[12px] leading-relaxed">
        📋 Isi <b>hitungan fisik</b> hasil opname gudang. Kolom sudah terisi angka versi sistem sebagai ancang-ancang —
        cukup ubah yang berbeda. Yang <b>kosong</b> dilewati (tidak diubah). Selisihnya dicatat sebagai
        <b>Penyesuaian</b> dan jadi <b>saldo awal</b> laporan mulai tanggal opname.
    </div>

    <form method="POST" action="{{ route('stok-opname.store') }}">
        @csrf

        <div class="bg-white rounded-2xl border border-stone-200 p-4 mb-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-[11px] font-semibold text-stone-500 mb-1">Tanggal opname (titik nol)</label>
                <input type="date" name="opname_date" value="{{ old('opname_date', '2026-07-14') }}" required
                    class="px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </div>
            <p class="text-[11px] text-stone-400 flex-1 min-w-[180px]">
                Stok akhir tanggal ini otomatis jadi stok awal hari/bulan berikutnya.
            </p>
        </div>

        <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
            <div class="hidden sm:grid grid-cols-12 gap-2 px-4 py-2.5 bg-stone-50 text-[10px] font-bold uppercase text-stone-500">
                <div class="col-span-6">Produk</div>
                <div class="col-span-2 text-right">Stok Sistem</div>
                <div class="col-span-2 text-right">Hitungan Fisik</div>
                <div class="col-span-2 text-right">Selisih</div>
            </div>

            @forelse($products as $p)
                <div class="grid grid-cols-12 gap-2 items-center px-4 py-3 border-t border-stone-100" data-row>
                    <div class="col-span-12 sm:col-span-6">
                        <div class="font-semibold text-sm text-stone-800">{{ $p->name }}</div>
                        <div class="text-[10px] text-stone-400 font-mono">{{ $p->sku ?: '—' }}</div>
                    </div>
                    <div class="col-span-4 sm:col-span-2 text-right">
                        <span class="sm:hidden text-[10px] text-stone-400 block">Sistem</span>
                        <span class="text-sm text-stone-600 font-mono" data-sys>{{ (int) $p->hq_stock }}</span>
                    </div>
                    <div class="col-span-4 sm:col-span-2 text-right">
                        <span class="sm:hidden text-[10px] text-stone-400 block">Fisik</span>
                        <input type="number" name="counts[{{ $p->id }}]" value="{{ (int) $p->hq_stock }}" min="0"
                            class="w-full px-2 py-1.5 border border-stone-300 rounded-lg text-sm text-right font-mono focus:border-amber-500 focus:ring-1 focus:ring-amber-500"
                            data-fisik oninput="opnameDiff(this)">
                    </div>
                    <div class="col-span-4 sm:col-span-2 text-right">
                        <span class="sm:hidden text-[10px] text-stone-400 block">Selisih</span>
                        <span class="text-sm font-mono text-stone-400" data-diff>0</span>
                    </div>
                </div>
            @empty
                <div class="px-4 py-8 text-center text-stone-400 text-sm">Belum ada produk.</div>
            @endforelse
        </div>

        <div class="mt-4 flex items-center gap-3">
            <button class="px-5 py-2.5 text-sm bg-amber-600 text-white rounded-xl hover:bg-amber-700 font-semibold"
                onclick="return confirm('Simpan opname? Selisih akan dicatat sebagai penyesuaian dan menyetel ulang stok pusat.')">
                Simpan Opname
            </button>
            <a href="{{ route('hq-stock.report') }}" class="text-sm text-stone-500 hover:text-stone-800">Lihat Laporan Stok HQ →</a>
        </div>
    </form>
</div>

<script>
function opnameDiff(input) {
    const row = input.closest('[data-row]');
    const sys = parseInt(row.querySelector('[data-sys]').textContent) || 0;
    const fisik = input.value === '' ? null : (parseInt(input.value) || 0);
    const el = row.querySelector('[data-diff]');
    if (fisik === null) { el.textContent = '—'; el.className = 'text-sm font-mono text-stone-300'; return; }
    const d = fisik - sys;
    el.textContent = d > 0 ? '+' + d : d;
    el.className = 'text-sm font-mono ' + (d === 0 ? 'text-stone-400' : d > 0 ? 'text-emerald-600 font-bold' : 'text-rose-600 font-bold');
}
</script>
@endsection
