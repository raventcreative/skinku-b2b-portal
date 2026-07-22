@extends('layouts.app')
@section('title', $deal->exists ? 'Edit Deal '.$deal->kode : 'Deal Baru')
@section('heading', $deal->exists ? 'Edit Deal — '.$deal->kode : 'Deal / Kerjasama Baru')

@section('content')
@php $canFinance = auth()->user()->canDo('kol.deal.finance'); @endphp
<div class="max-w-3xl">
    <a href="{{ route('kol-deals.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Daftar Deal</a>

    <form method="POST" action="{{ $deal->exists ? route('kol-deals.update', $deal) : route('kol-deals.store') }}"
        class="bg-white rounded-2xl border border-stone-200 p-5 mt-3">
        @csrf
        @if($deal->exists) @method('PUT') @endif

        <div class="grid sm:grid-cols-2 gap-3 text-sm mb-4">
            <label class="text-[11px] font-semibold text-stone-500">KOL
                {{-- Ketik untuk cari — 100+ KOL tak nyaman di select biasa. Teks
                     dipetakan ke kol_id (hidden) via JS; server tetap validasi id. --}}
                <input type="text" id="kolSearch" list="kolDatalist" autocomplete="off" required
                    placeholder="ketik untuk cari @username…"
                    class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                <datalist id="kolDatalist">
                    @foreach($kols as $k)<option value="{{ '@'.$k->tiktok_username }}">@endforeach
                </datalist>
                <input type="hidden" name="kol_id" id="kolId" value="{{ old('kol_id', $selectedKolId ?: '') }}">
                <span id="kolMiss" class="block mt-1 text-[10px] text-rose-500 hidden">KOL tak ditemukan — pilih dari daftar.</span>
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Jenis
                <select name="jenis" required class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    @foreach(\App\Models\KolDeal::JENIS as $j)
                        <option value="{{ $j }}" @selected(old('jenis', $deal->jenis) === $j)>{{ strtoupper($j) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Ratecard deal (Rp)
                <input type="number" name="ratecard_deal" required min="0" value="{{ old('ratecard_deal', $deal->ratecard_deal) }}"
                    class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Jumlah slot (untuk VT)
                <input type="number" name="jumlah_slot" required min="1" value="{{ old('jumlah_slot', $deal->jumlah_slot ?? 1) }}"
                    class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Periode mulai
                <input type="date" name="periode_mulai" value="{{ old('periode_mulai', $deal->periode_mulai?->format('Y-m-d')) }}"
                    class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Periode selesai
                <input type="date" name="periode_selesai" value="{{ old('periode_selesai', $deal->periode_selesai?->format('Y-m-d')) }}"
                    class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500">PIC
                <select name="pic_user_id" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    <option value="">— pilih PIC —</option>
                    @foreach($pics as $p)
                        <option value="{{ $p->id }}" @selected(old('pic_user_id', $deal->pic_user_id) == $p->id)>{{ $p->fullname }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Link MOU
                <input type="url" name="link_mou" maxlength="255" value="{{ old('link_mou', $deal->link_mou) }}"
                    placeholder="https://…" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Status
                <select name="status" required class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    @foreach(\App\Models\KolDeal::STATUSES as $st)
                        <option value="{{ $st }}" @selected(old('status', $deal->status ?? 'draft') === $st)>{{ $st }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        @if($canFinance)
            {{-- Blok finansial: HANYA dirender untuk pemegang kol.deal.finance.
                 Server tetap membuang field ini dari input siapa pun yang tak
                 punya izin (lihat KolDealController::validated) — form ini bukan
                 pengamanannya, cuma tampilannya. --}}
            <div class="border-t border-stone-100 pt-4 mb-4">
                <p class="text-[11px] font-bold uppercase tracking-wide text-stone-400 mb-2">Finansial</p>
                <div class="grid sm:grid-cols-2 gap-3 text-sm">
                    <label class="text-[11px] font-semibold text-stone-500">Total biaya (Rp)
                        <input type="number" name="total_biaya" min="0" value="{{ old('total_biaya', $deal->total_biaya) }}"
                            class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    </label>
                    <label class="text-[11px] font-semibold text-stone-500">Status bayar
                        <select name="status_bayar" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                            @foreach(\App\Models\KolDeal::STATUS_BAYAR as $sb)
                                <option value="{{ $sb }}" @selected(old('status_bayar', $deal->status_bayar ?? 'belum') === $sb)>{{ $sb }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-[11px] font-semibold text-stone-500">No. rekening
                        <input name="no_rekening" maxlength="50" value="{{ old('no_rekening', $deal->no_rekening) }}"
                            class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    </label>
                    <label class="text-[11px] font-semibold text-stone-500">Bank
                        <input name="bank" maxlength="100" value="{{ old('bank', $deal->bank) }}"
                            class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    </label>
                    <label class="text-[11px] font-semibold text-stone-500">Atas nama
                        <input name="atas_nama" maxlength="150" value="{{ old('atas_nama', $deal->atas_nama) }}"
                            class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    </label>
                </div>
            </div>
        @endif

        @if($errors->any())
            <p class="mb-3 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs">{{ $errors->first() }}</p>
        @endif

        <button class="px-5 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold">
            {{ $deal->exists ? 'Simpan Perubahan' : 'Buat Deal' }}
        </button>
    </form>
</div>

<script>
(function () {
    // Peta "@username" -> kol_id (dibangun di controller, bukan array-literal di Blade).
    const MAP = {!! json_encode($kolMap) !!};
    const search = document.getElementById('kolSearch');
    const hidden = document.getElementById('kolId');
    const miss = document.getElementById('kolMiss');

    // Prefill saat edit / ?kol=: tampilkan @username dari id terpilih.
    if (hidden.value) {
        const name = Object.keys(MAP).find(u => String(MAP[u]) === String(hidden.value));
        if (name) search.value = name;
    }

    const resolve = () => {
        const id = MAP[search.value.trim()];
        hidden.value = id || '';
        miss.classList.toggle('hidden', !!id || search.value.trim() === '');
        return !!id;
    };
    search.addEventListener('input', resolve);
    search.addEventListener('change', resolve);
    search.closest('form').addEventListener('submit', (e) => {
        if (!resolve()) { e.preventDefault(); miss.classList.remove('hidden'); search.focus(); }
    });
})();
</script>
@endsection
