@extends('layouts.app')
@section('title', $deal->exists ? 'Edit Deal '.$deal->kode : 'Deal Baru')
@section('heading', $deal->exists ? 'Edit Deal — '.$deal->kode : 'Deal / Kerjasama Baru')

@section('content')
@php
    $canFinance = auth()->user()->canDo('kol.deal.finance');
    $canApprove = auth()->user()->canDo('kol.deal.approve');   // boleh set berjalan/batal
@endphp
<div class="max-w-3xl">
    <a href="{{ route('kol-deals.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Daftar Deal</a>

    <form method="POST" action="{{ $deal->exists ? route('kol-deals.update', $deal) : route('kol-deals.store') }}"
        class="bg-white rounded-2xl border border-stone-200 p-5 mt-3">
        @csrf
        @if($deal->exists) @method('PUT') @endif

        <div class="grid sm:grid-cols-2 gap-3 text-sm mb-4">
            <div class="text-[11px] font-semibold text-stone-500">KOL
                {{-- Ketik untuk cari — 100+ KOL tak nyaman di select biasa. Teks
                     dipetakan ke kol_id (hidden) via JS; server tetap validasi id. --}}
                <input type="text" id="kolSearch" list="kolDatalist" autocomplete="off" required
                    placeholder="ketik untuk cari @username…"
                    class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm font-normal">
                <datalist id="kolDatalist">
                    @foreach($kols as $k)<option value="{{ '@'.$k->tiktok_username }}">@endforeach
                </datalist>
                <input type="hidden" name="kol_id" id="kolId" value="{{ old('kol_id', $selectedKolId ?: '') }}">
                <span id="kolMiss" class="block mt-1 text-[10px] text-rose-500 hidden">KOL tak ditemukan — pilih dari daftar.</span>

                {{-- No. HP KOL: boleh diisi/ubah di sini (sering nomornya baru dikasih
                     saat dealing). Ter-prefill dari data KOL bila ada, dan TERSIMPAN
                     balik ke data KOL saat deal disimpan (satu sumber, tak dobel). --}}
                <div class="mt-2">
                    <span class="block text-[10px] text-stone-400">No. HP KOL — boleh diisi/ubah di sini, ikut tersimpan ke data KOL</span>
                    <div class="flex items-center gap-2 mt-0.5">
                        <input type="text" name="kol_phone" id="kolPhone" maxlength="30" placeholder="mis. 0812…"
                            value="{{ old('kol_phone') }}"
                            class="flex-1 px-3 py-1.5 border border-stone-300 rounded-lg text-sm font-normal">
                        <a id="kolWa" href="#" target="_blank" rel="noopener"
                            class="hidden text-[11px] text-emerald-700 hover:underline font-normal whitespace-nowrap">📱 WA</a>
                    </div>
                </div>
            </div>
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
                        @php $lockApproval = in_array($st, ['berjalan', 'batal'], true) && ! $canApprove && ($deal->status ?? 'draft') !== $st; @endphp
                        <option value="{{ $st }}" @selected(old('status', $deal->status ?? 'draft') === $st) @disabled($lockApproval)>{{ $st }}@if($lockApproval) (perlu penyetuju)@endif</option>
                    @endforeach
                </select>
                @unless($canApprove)<span class="block mt-1 text-[10px] text-stone-400">Acc (berjalan) / Tolak (batal) hanya oleh penyetuju.</span>@endunless
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Campaign (opsional)
                <select name="kol_campaign_id" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm bg-white">
                    <option value="">— tanpa campaign —</option>
                    @foreach($campaigns as $cmp)
                        <option value="{{ $cmp->id }}" @selected(old('kol_campaign_id', $deal->kol_campaign_id) == $cmp->id)>{{ $cmp->name }}</option>
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

        @if($deal->exists)
            {{-- Laporan Hasil Endorse (Evaluasi Kinerja) — diisi setelah endorse jalan.
                 CPM/ROMI/verdict dihitung otomatis, verdict menyesuaikan tujuan. --}}
            <div class="border-t border-stone-100 pt-4 mb-4">
                <p class="text-[11px] font-bold uppercase tracking-wide text-stone-400 mb-2">Laporan Hasil Endorse</p>
                <div class="grid sm:grid-cols-2 gap-3 text-sm">
                    <label class="text-[11px] font-semibold text-stone-500">Tujuan endorse
                        <select name="hasil_tujuan" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                            <option value="">— pilih tujuan —</option>
                            <option value="penjualan" @selected(old('hasil_tujuan', $deal->hasil_tujuan) === 'penjualan')>Penjualan (dinilai dari ROMI)</option>
                            <option value="awareness" @selected(old('hasil_tujuan', $deal->hasil_tujuan) === 'awareness')>Awareness / Views (dinilai dari CPM)</option>
                        </select>
                    </label>
                    <label class="text-[11px] font-semibold text-stone-500">Total video ter-upload
                        <input type="number" name="hasil_video_upload" min="0" value="{{ old('hasil_video_upload', $deal->hasil_video_upload) }}"
                            class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    </label>
                    <label class="text-[11px] font-semibold text-stone-500">Jumlah video FYP
                        <input type="number" name="hasil_video_fyp" min="0" value="{{ old('hasil_video_fyp', $deal->hasil_video_fyp) }}"
                            class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    </label>
                    <label class="text-[11px] font-semibold text-stone-500">Total views
                        <input type="number" name="hasil_views" min="0" value="{{ old('hasil_views', $deal->hasil_views) }}"
                            class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    </label>
                    <label class="text-[11px] font-semibold text-stone-500">Total revenue (Rp) <span class="text-stone-400 font-normal">— boleh kosong bila tujuan awareness</span>
                        <input type="number" name="hasil_revenue" min="0" value="{{ old('hasil_revenue', $deal->hasil_revenue) }}"
                            class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    </label>
                    <label class="text-[11px] font-semibold text-stone-500 sm:col-span-2">Catatan hasil
                        <textarea name="hasil_catatan" rows="2" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">{{ old('hasil_catatan', $deal->hasil_catatan) }}</textarea>
                    </label>
                </div>
                @if($deal->hasil_terisi)
                    <div class="mt-3 flex flex-wrap items-center gap-4 text-xs bg-stone-50 rounded-xl p-3">
                        <span class="font-bold">Verdict: {{ $deal->hasil_verdict }}</span>
                        <span class="text-stone-500">Rata-rata views/video: <b>{{ $deal->hasil_avg_views !== null ? number_format($deal->hasil_avg_views, 0, ',', '.') : '—' }}</b></span>
                        @if($canFinance)
                            <span class="text-stone-500">CPM: <b>{{ $deal->hasil_cpm !== null ? 'Rp '.number_format($deal->hasil_cpm, 0, ',', '.') : '—' }}</b></span>
                            <span class="text-stone-500">ROMI: <b>{{ $deal->hasil_romi !== null ? $deal->hasil_romi.'×' : '—' }}</b></span>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        @if($errors->any())
            <p class="mb-3 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs">{{ $errors->first() }}</p>
        @endif

        <button class="px-5 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold">
            {{ $deal->exists ? 'Simpan Perubahan' : 'Buat Deal' }}
        </button>
    </form>

    @if($deal->exists)
        @php
            $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
            $samples = $deal->samples;
            $totalHpp = $samples->sum(fn ($s) => $s->subtotal);
            $sTone = ['pending' => 'bg-stone-100 text-stone-500', 'shipped' => 'bg-amber-100 text-amber-700', 'received' => 'bg-emerald-100 text-emerald-700'];
        @endphp
        <div class="bg-white rounded-2xl border border-stone-200 p-5 mt-4">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm font-bold text-stone-800">Sampel Produk</p>
                <span class="text-xs text-stone-500">Total HPP: <b class="text-stone-800">{{ $rp($totalHpp) }}</b></span>
            </div>

            {{-- Daftar sampel --}}
            <div class="space-y-2 mb-4">
                @forelse($samples as $s)
                    <div class="border border-stone-200 rounded-xl p-3">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-stone-800">{{ $s->product }}</p>
                                <p class="text-[11px] text-stone-500 tabular-nums">{{ number_format($s->units, 0, ',', '.') }} unit × {{ $rp($s->unit_cost) }} = <b>{{ $rp($s->subtotal) }}</b></p>
                                @if($s->courier || $s->tracking_no)
                                    <p class="text-[11px] text-stone-400">{{ $s->courier }}{{ $s->courier && $s->tracking_no ? ' · ' : '' }}{{ $s->tracking_no }}</p>
                                @endif
                                <p class="text-[10px] text-stone-400">
                                    @if($s->shipped_at)dikirim {{ $s->shipped_at->format('d M') }}@endif
                                    @if($s->received_at) · diterima {{ $s->received_at->format('d M') }}@endif
                                </p>
                            </div>
                            <div class="flex items-center gap-1.5 shrink-0">
                                <form method="POST" action="{{ route('kol-samples.status', $s) }}">
                                    @csrf @method('PATCH')
                                    <select name="status" onchange="this.form.submit()" class="text-[11px] px-2 py-1 border border-stone-300 rounded-lg bg-white {{ $sTone[$s->status] ?? '' }}">
                                        @foreach(\App\Models\KolSample::STATUS_LABELS as $val => $lbl)
                                            <option value="{{ $val }}" @selected($s->status === $val)>{{ $lbl }}</option>
                                        @endforeach
                                    </select>
                                </form>
                                <form method="POST" action="{{ route('kol-samples.destroy', $s) }}" onsubmit="return confirm('Hapus sampel ini?')">
                                    @csrf @method('DELETE')
                                    <button class="text-[11px] text-rose-400 hover:text-rose-600">hapus</button>
                                </form>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-stone-400">Belum ada sampel dicatat.</p>
                @endforelse
            </div>

            {{-- Tambah sampel --}}
            <details class="border-t border-stone-100 pt-3">
                <summary class="cursor-pointer text-xs font-semibold text-stone-600">+ Catat sampel</summary>
                <form method="POST" action="{{ route('kol-samples.store', $deal) }}" class="mt-3 grid sm:grid-cols-2 gap-3 text-sm">
                    @csrf
                    <label class="text-[11px] font-semibold text-stone-500 sm:col-span-2">Produk
                        <input name="product" required maxlength="255" placeholder="mis. Serum Glow 30ml" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    </label>
                    <label class="text-[11px] font-semibold text-stone-500">Jumlah unit
                        <input type="number" name="units" min="1" value="1" required class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    </label>
                    <label class="text-[11px] font-semibold text-stone-500">HPP per unit (Rp)
                        <input type="number" name="unit_cost" min="0" value="{{ $sampleHppDefault ?? 0 }}" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    </label>
                    <label class="text-[11px] font-semibold text-stone-500">Kurir
                        <input name="courier" maxlength="100" placeholder="mis. JNE" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    </label>
                    <label class="text-[11px] font-semibold text-stone-500">No. resi
                        <input name="tracking_no" maxlength="100" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    </label>
                    <label class="text-[11px] font-semibold text-stone-500">Status
                        <select name="status" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm bg-white">
                            @foreach(\App\Models\KolSample::STATUS_LABELS as $val => $lbl)<option value="{{ $val }}">{{ $lbl }}</option>@endforeach
                        </select>
                    </label>
                    @if($canFinance)
                        <label class="flex items-center gap-2 mt-5 text-xs text-stone-600">
                            <input type="checkbox" name="add_to_deal" value="1"> Tambahkan HPP ke biaya deal
                        </label>
                    @endif
                    <div class="sm:col-span-2">
                        <button class="px-4 py-2 text-sm bg-stone-700 text-white rounded-lg hover:bg-stone-800 font-semibold">Simpan sampel</button>
                    </div>
                </form>
            </details>
        </div>
    @endif
</div>

<script>
(function () {
    // Peta "@username" -> {id, phone, wa}. Js::from = cara aman & konsisten
    // menyuntik data PHP ke JS (bukan array-literal di Blade echo yang 500).
    const MAP = {{ \Illuminate\Support\Js::from($kolMap) }};
    const search = document.getElementById('kolSearch');
    const hidden = document.getElementById('kolId');
    const miss = document.getElementById('kolMiss');
    const phone = document.getElementById('kolPhone');
    const wa = document.getElementById('kolWa');

    // Link WhatsApp mengikuti isi No. HP (08xx → 62xx), update saat diketik.
    const waUrl = (p) => {
        let d = (p || '').replace(/\D/g, '');
        if (!d) return null;
        if (d[0] === '0') d = '62' + d.slice(1);
        return 'https://wa.me/' + d;
    };
    const refreshWa = () => {
        const u = waUrl(phone.value);
        if (u) { wa.href = u; wa.classList.remove('hidden'); } else wa.classList.add('hidden');
    };

    const resolve = (fromUser) => {
        const k = MAP[search.value.trim()];
        hidden.value = k ? k.id : '';
        miss.classList.toggle('hidden', !!k || search.value.trim() === '');
        // Pilih KOL → tampilkan nomornya (masih bisa diubah). Tak menimpa saat load.
        if (k && fromUser) { phone.value = k.phone || ''; refreshWa(); }
        return !!k;
    };

    // Prefill saat edit / ?kol=: @username + nomor (tanpa menimpa old() dari error validasi).
    if (hidden.value) {
        const name = Object.keys(MAP).find(u => String(MAP[u].id) === String(hidden.value));
        if (name) { search.value = name; if (!phone.value) phone.value = MAP[name].phone || ''; }
    }
    refreshWa();

    search.addEventListener('input', () => resolve(true));
    search.addEventListener('change', () => resolve(true));
    phone.addEventListener('input', refreshWa);
    search.closest('form').addEventListener('submit', (e) => {
        if (!resolve(false)) { e.preventDefault(); miss.classList.remove('hidden'); search.focus(); }
    });
})();
</script>
@endsection
