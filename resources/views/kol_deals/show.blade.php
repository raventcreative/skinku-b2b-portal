@extends('layouts.app')
@section('title', 'Deal '.$deal->kode)
@section('heading', 'Deal — '.$deal->kode)

@section('content')
@php
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $statusBadge = [
        'draft' => 'bg-stone-100 text-stone-600', 'berjalan' => 'bg-blue-100 text-blue-700',
        'selesai' => 'bg-emerald-100 text-emerald-700', 'batal' => 'bg-rose-100 text-rose-700',
    ];
    $sTone = ['pending' => 'bg-stone-100 text-stone-500', 'shipped' => 'bg-amber-100 text-amber-700', 'received' => 'bg-emerald-100 text-emerald-700'];
    $deadline = $deal->posting_deadline;
    $overduePost = $deadline && $deadline->isPast() && $deal->status !== 'selesai';
    $soonPost = $deadline && ! $overduePost && $deadline->lte(now()->addDay());
@endphp

<div class="max-w-3xl space-y-4">
    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ route('kol-deals.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Daftar Deal</a>
        <a href="{{ route('kol-deals.edit', $deal) }}" class="ml-auto px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700">Edit deal</a>
    </div>

    {{-- Ringkasan --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-lg font-bold text-stone-800">{{ $deal->kode }}</p>
                <a href="{{ route('kols.show', $deal->kol_id) }}" class="text-red-700 hover:underline font-semibold">{{ '@'.($deal->kol->tiktok_username ?? '?') }}</a>
                @if($deal->campaign)<span class="ml-2 text-[11px] text-indigo-500">📣 {{ $deal->campaign->name }}</span>@endif
            </div>
            <span class="px-2.5 py-1 rounded-full text-[11px] font-bold {{ $statusBadge[$deal->status] ?? 'bg-stone-100 text-stone-600' }}">{{ ucfirst($deal->status) }}</span>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4 text-sm">
            <div><p class="text-[11px] text-stone-400">Tipe deal</p><p class="font-semibold text-stone-700">{{ $deal->dealTypeLabel() }}</p></div>
            <div><p class="text-[11px] text-stone-400">Format</p><p class="font-semibold text-stone-700 uppercase">{{ $deal->jenis }}{{ $deal->jenis === 'vt' ? ' ×'.$deal->jumlah_slot : '' }}</p></div>
            <div><p class="text-[11px] text-stone-400">Ratecard</p><p class="font-semibold text-stone-700 tabular-nums">{{ $rp($deal->ratecard_deal) }}</p></div>
            <div><p class="text-[11px] text-stone-400">PIC</p><p class="font-semibold text-stone-700">{{ $deal->pic->fullname ?? '—' }}</p></div>
            <div><p class="text-[11px] text-stone-400">Periode</p><p class="font-semibold text-stone-700">{{ $deal->periode_mulai?->format('d M') ?? '—' }} – {{ $deal->periode_selesai?->format('d M Y') ?? '—' }}</p></div>
            @if($deal->link_mou)<div><p class="text-[11px] text-stone-400">MOU</p><a href="{{ $deal->link_mou }}" target="_blank" rel="noopener" class="font-semibold text-indigo-600 hover:underline">buka →</a></div>@endif
        </div>
    </div>

    {{-- Deliverables & jadwal --}}
    @if($deal->deliverables || $deal->posting_deadline || $deal->usage_rights || $deal->internal_notes)
        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <p class="text-[11px] font-bold uppercase tracking-wide text-stone-400 mb-3">Deliverables &amp; Jadwal</p>
            <div class="space-y-3 text-sm">
                @if($deal->deliverables)
                    <div><p class="text-[11px] text-stone-400">Deliverables</p><p class="text-stone-700 whitespace-pre-line">{{ $deal->deliverables }}</p></div>
                @endif
                <div class="flex flex-wrap gap-x-8 gap-y-3">
                    @if($deadline)
                        <div>
                            <p class="text-[11px] text-stone-400">Deadline posting</p>
                            <p class="font-semibold text-stone-700">{{ $deadline->format('d M Y') }}
                                @if($overduePost)<span class="ml-1 text-[10px] px-1.5 py-0.5 rounded-full bg-rose-100 text-rose-700">lewat tenggat</span>
                                @elseif($soonPost)<span class="ml-1 text-[10px] px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700">≤ H-1</span>@endif
                            </p>
                        </div>
                    @endif
                    @if($deal->usage_rights)
                        <div><p class="text-[11px] text-stone-400">Usage rights</p><p class="font-semibold text-stone-700">{{ $deal->usage_rights }}</p></div>
                    @endif
                </div>
                @if($deal->internal_notes)
                    <div><p class="text-[11px] text-stone-400">Catatan internal</p><p class="text-stone-600 whitespace-pre-line">{{ $deal->internal_notes }}</p></div>
                @endif
            </div>
        </div>
    @endif

    {{-- Finansial + payment chip (finance-only) --}}
    @if($canFinance)
        @php
            $chip = match ($deal->status_bayar) {
                'lunas' => ['bg-emerald-100 text-emerald-700', 'Lunas'],
                'dp' => ['bg-amber-100 text-amber-700', 'DP '.$deal->dp_percent.'% ('.$rp($deal->dpAmount()).')'],
                default => $deal->isPaymentOverdue()
                    ? ['bg-rose-100 text-rose-700', 'Belum bayar · lewat tenggat']
                    : ['bg-stone-100 text-stone-600', 'Belum dibayar'],
            };
        @endphp
        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <div class="flex items-center justify-between mb-3">
                <p class="text-[11px] font-bold uppercase tracking-wide text-stone-400">Finansial</p>
                <span class="px-2.5 py-1 rounded-full text-[11px] font-bold {{ $chip[0] }}">{{ $chip[1] }}</span>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                <div><p class="text-[11px] text-stone-400">Fee</p><p class="font-semibold text-stone-700 tabular-nums">{{ $rp($deal->total_biaya) }}</p></div>
                <div><p class="text-[11px] text-stone-400">Biaya lain</p><p class="font-semibold text-stone-700 tabular-nums">{{ $rp($deal->other_cost) }}</p></div>
                <div><p class="text-[11px] text-stone-400">HPP sampel</p><p class="font-semibold text-stone-700 tabular-nums">{{ $rp($deal->samples->sum(fn ($s) => $s->subtotal)) }}</p></div>
                <div><p class="text-[11px] text-stone-400">Grand total</p><p class="font-bold text-stone-900 tabular-nums">{{ $rp($deal->grandTotal()) }}</p></div>
            </div>
            @if($deal->no_rekening || $deal->bank || $deal->payment_note)
                <div class="mt-3 pt-3 border-t border-stone-100 text-xs text-stone-500 flex flex-wrap gap-x-6 gap-y-1">
                    @if($deal->bank)<span>Bank: <b class="text-stone-700">{{ $deal->bank }}</b></span>@endif
                    @if($deal->no_rekening)<span>Rek: <b class="text-stone-700">{{ $deal->no_rekening }}</b> {{ $deal->atas_nama ? 'a.n. '.$deal->atas_nama : '' }}</span>@endif
                    @if($deal->payment_note)<span>Bukti: <b class="text-stone-700">{{ $deal->payment_note }}</b></span>@endif
                </div>
            @endif
        </div>
    @endif

    {{-- Konten tertaut + views agregat --}}
    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <div class="flex items-center justify-between mb-3">
            <p class="text-[11px] font-bold uppercase tracking-wide text-stone-400">Konten Tertaut</p>
            <div class="flex items-center gap-4 text-xs">
                <span class="text-stone-500">Total views: <b class="text-stone-800 tabular-nums">{{ number_format($contentViews, 0, ',', '.') }}</b></span>
                @if($canFinance && $contentCpm !== null)<span class="text-stone-500">CPM aktual: <b class="text-stone-800 tabular-nums">{{ $rp($contentCpm) }}</b></span>@endif
            </div>
        </div>
        @forelse($deal->contents as $c)
            <div class="flex items-center justify-between py-1.5 border-t border-stone-50 text-sm">
                <a href="{{ route('kol-konten.show', $c) }}" class="text-stone-700 hover:text-red-700 truncate max-w-[60%]">{{ $c->title ?: $c->url }}</a>
                <span class="text-xs text-stone-500 tabular-nums">{{ number_format((int) ($c->latestSnapshot->views ?? 0), 0, ',', '.') }} views
                    <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] {{ $c->label === 'paid' ? 'bg-red-50 text-red-600' : 'bg-stone-100 text-stone-500' }}">{{ $c->label }}</span></span>
            </div>
        @empty
            <p class="text-sm text-stone-400 italic">Belum ada konten tertaut ke deal ini.</p>
        @endforelse
    </div>

    {{-- Sampel produk --}}
    @if($deal->samples->isNotEmpty())
        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <p class="text-[11px] font-bold uppercase tracking-wide text-stone-400 mb-3">Sampel Produk</p>
            <div class="space-y-2">
                @foreach($deal->samples as $s)
                    <div class="flex items-center justify-between text-sm">
                        <div>
                            <span class="font-semibold text-stone-700">{{ $s->product }}</span>
                            <span class="text-[11px] text-stone-500 tabular-nums">— {{ number_format($s->units, 0, ',', '.') }} unit × {{ $rp($s->unit_cost) }} = {{ $rp($s->subtotal) }}</span>
                        </div>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $sTone[$s->status] ?? 'bg-stone-100 text-stone-500' }}">{{ $s->status }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Hasil endorse --}}
    @if($deal->hasil_terisi)
        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <p class="text-[11px] font-bold uppercase tracking-wide text-stone-400 mb-3">Laporan Hasil Endorse</p>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                <span class="font-bold">Verdict: {{ $deal->hasil_verdict }}</span>
                <span class="text-stone-500">Views: <b class="text-stone-700 tabular-nums">{{ number_format((int) $deal->hasil_views, 0, ',', '.') }}</b></span>
                <span class="text-stone-500">Rata2 views/video: <b class="text-stone-700 tabular-nums">{{ $deal->hasil_avg_views !== null ? number_format($deal->hasil_avg_views, 0, ',', '.') : '—' }}</b></span>
                @if($canFinance)
                    <span class="text-stone-500">CPM: <b class="text-stone-700 tabular-nums">{{ $deal->hasil_cpm !== null ? $rp($deal->hasil_cpm) : '—' }}</b></span>
                    <span class="text-stone-500">ROMI: <b class="text-stone-700 tabular-nums">{{ $deal->hasil_romi !== null ? $deal->hasil_romi.'×' : '—' }}</b></span>
                @endif
            </div>
            @if($deal->hasil_catatan)<p class="mt-2 text-xs text-stone-500 whitespace-pre-line">{{ $deal->hasil_catatan }}</p>@endif
        </div>
    @endif
</div>
@endsection
