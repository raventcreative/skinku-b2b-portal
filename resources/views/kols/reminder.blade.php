@extends('layouts.app')
@section('title', 'Reminder KOL')
@section('heading', 'Reminder KOL')

@section('content')
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp
<div class="max-w-4xl space-y-4">

    <p class="text-sm text-stone-500">Yang mendesak — kerjakan dari atas.</p>

    <div class="flex flex-wrap gap-2">
        <span class="text-xs px-3 py-1 rounded-full {{ $lateCount ? 'bg-rose-100 text-rose-700' : 'bg-stone-100 text-stone-500' }}">{{ $lateCount }} terlambat</span>
        <span class="text-xs px-3 py-1 rounded-full {{ $dueCount ? 'bg-amber-100 text-amber-700' : 'bg-stone-100 text-stone-500' }}">{{ $dueCount }} hari ini</span>
        <span class="text-xs px-3 py-1 rounded-full {{ $besokCount ? 'bg-amber-50 text-amber-600' : 'bg-stone-100 text-stone-500' }}">{{ $besokCount }} besok (H-1)</span>
        <span class="text-xs px-3 py-1 rounded-full {{ $noneCount ? 'bg-amber-100 text-amber-700' : 'bg-stone-100 text-stone-500' }}">{{ $noneCount }} tanpa next action</span>
    </div>

    {{-- ⏰ Pipeline --}}
    <div>
        <p class="text-sm font-semibold text-stone-700 mb-2 flex items-center gap-1.5"><span>⏰</span> Pipeline</p>
        @if($rows->isEmpty())
            <div class="px-4 py-8 rounded-2xl border border-dashed border-stone-300 text-center text-sm text-stone-500">Tak ada tindak lanjut pipeline yang mendesak. 🎉</div>
        @else
            <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden divide-y divide-stone-100">
                @foreach($rows as $c)
                    @php
                        $late = $c->next_action_at && $c->next_action_at->lt($today);
                        $due = $c->next_action_at && $c->next_action_at->isSameDay($today);
                        $besok = $c->next_action_at && $c->next_action_at->isSameDay($today->copy()->addDay());
                        [$catLbl, $catTone] = $late ? ['Terlambat', 'bg-rose-100 text-rose-700']
                            : ($due ? ['Hari ini', 'bg-amber-100 text-amber-700']
                            : ($besok ? ['Besok', 'bg-amber-50 text-amber-600'] : ['Tanpa aksi', 'bg-stone-100 text-stone-500']));
                    @endphp
                    <div class="flex items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-[10px] px-2 py-0.5 rounded-full {{ $catTone }}">{{ $catLbl }}</span>
                                <a href="{{ route('kols.show', $c->kol_id) }}" class="text-sm font-semibold text-indigo-600 hover:underline">{{ '@'.$c->kol->tiktok_username }}</a>
                                <span class="text-[10px] uppercase tracking-wide text-stone-400">{{ \App\Models\KolPipelineCard::STAGE_LABELS[$c->stage] ?? $c->stage }}</span>
                            </div>
                            <p class="text-xs text-stone-500 truncate mt-0.5">{{ $c->next_action ?? '— belum ada next action —' }}</p>
                        </div>
                        <div class="text-right shrink-0">
                            @if($c->next_action_at)
                                <p class="text-xs {{ $late ? 'text-rose-600 font-medium' : 'text-stone-500' }}">{{ $c->next_action_at->format('d M Y') }}</p>
                                @if($late)<p class="text-[10px] text-rose-500">terlambat {{ (int) $c->next_action_at->diffInDays($today) }} hari</p>@endif
                            @else
                                <p class="text-[10px] text-amber-600">tanpa tanggal</p>
                            @endif
                            <a href="{{ route('kol-pipeline.show', $c) }}" class="text-[11px] text-indigo-600 hover:underline">Buka →</a>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- 📦 Sampel tertahan --}}
    @if($stuckSamples->isNotEmpty())
        <div>
            <p class="text-sm font-semibold text-stone-700 mb-2 flex items-center gap-1.5"><span>📦</span> Sampel tertahan</p>
            <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden divide-y divide-stone-100">
                @foreach($stuckSamples as $s)
                    @php
                        $isPending = $s->status === 'pending';
                        $days = $isPending ? (int) $s->created_at->diffInDays(now()) : (int) optional($s->shipped_at)->diffInDays(now());
                    @endphp
                    <div class="flex items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-[10px] px-2 py-0.5 rounded-full {{ $isPending ? 'bg-stone-100 text-stone-500' : 'bg-amber-100 text-amber-700' }}">{{ $isPending ? 'Belum kirim' : 'Belum diterima' }}</span>
                                <span class="text-sm font-semibold text-stone-800">{{ $s->product }}</span>
                                <span class="text-[10px] text-stone-400">{{ '@'.($s->kol->tiktok_username ?? '?') }}</span>
                            </div>
                            <p class="text-[11px] text-stone-500 mt-0.5">{{ $isPending ? 'belum dikirim' : 'dikirim' }} {{ $days }} hari{{ $s->tracking_no ? ' · resi '.$s->tracking_no : '' }}</p>
                        </div>
                        @if($s->kol_deal_id)
                            <a href="{{ route('kol-deals.edit', $s->kol_deal_id) }}" class="text-[11px] text-indigo-600 hover:underline shrink-0">Buka deal →</a>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- 💸 Pembayaran deal belum lunas (finance) — window H-1 --}}
    @if($payments->isNotEmpty())
        <div>
            <p class="text-sm font-semibold text-stone-700 mb-2 flex items-center gap-1.5"><span>💸</span> Pembayaran deal belum lunas</p>
            <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden divide-y divide-stone-100">
                @foreach($payments as $d)
                    @php
                        $due = $d->periode_selesai;
                        $overdue = $due && $due->lt($today);
                        $soon = $due && ! $overdue && $due->lte($today->copy()->addDay());  // H-1 / hari ini
                    @endphp
                    <div class="flex items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                @if($overdue)<span class="text-[10px] px-2 py-0.5 rounded-full bg-rose-100 text-rose-700">Lewat tenggat</span>
                                @elseif($soon)<span class="text-[10px] px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">Jatuh tempo ≤ H-1</span>@endif
                                <a href="{{ route('kol-deals.edit', $d) }}" class="text-sm font-semibold text-indigo-600 hover:underline">{{ '@'.$d->kol->tiktok_username }}</a>
                                <span class="text-[10px] uppercase tracking-wide text-stone-400">{{ $d->kode }}</span>
                                <span class="text-[10px] px-1.5 py-0.5 rounded {{ $d->status_bayar === 'dp' ? 'bg-sky-100 text-sky-700' : 'bg-stone-100 text-stone-500' }}">bayar {{ $d->status_bayar }}</span>
                            </div>
                            @if($d->status_bayar === 'dp')<p class="text-[10px] text-stone-400 mt-0.5">sudah DP sebagian — sisa nominal belum tercatat</p>@endif
                        </div>
                        <div class="text-right shrink-0">
                            <p class="text-xs font-medium text-stone-700">{{ $rp($d->total_biaya) }}</p>
                            <p class="text-[10px] {{ $overdue ? 'text-rose-500' : 'text-stone-400' }}">{{ $due?->format('d M Y') ?? 'tanpa tenggat' }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- 📹 Deadline posting --}}
    @if($postingDue->isNotEmpty())
        <div>
            <p class="text-sm font-semibold text-stone-700 mb-2 flex items-center gap-1.5"><span>📹</span> Deal berjalan belum ada konten</p>
            <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden divide-y divide-stone-100">
                @foreach($postingDue as $d)
                    <div class="flex items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <a href="{{ route('kol-deals.edit', $d) }}" class="text-sm font-semibold text-indigo-600 hover:underline">{{ '@'.$d->kol->tiktok_username }}</a>
                            <span class="ml-2 text-[10px] uppercase tracking-wide text-stone-400">{{ $d->kode }} · {{ $d->jenis }}</span>
                        </div>
                        <p class="text-xs {{ $d->periode_selesai->lt($today) ? 'text-rose-600 font-medium' : 'text-amber-600' }} shrink-0">tenggat {{ $d->periode_selesai->format('d M Y') }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- 💤 Affiliate berhenti posting --}}
    @if($churn->isNotEmpty())
        <div>
            <p class="text-sm font-semibold text-stone-700 mb-2 flex items-center gap-1.5"><span>💤</span> Affiliate berhenti posting (≥ 2 minggu)</p>
            <div class="bg-white rounded-2xl border border-stone-200 p-3 flex flex-wrap gap-2">
                @foreach($churn as $k)
                    <a href="{{ route('kols.show', $k->id) }}" class="text-xs px-3 py-1.5 rounded-full bg-stone-100 text-stone-600 hover:bg-stone-200">{{ '@'.$k->tiktok_username }}</a>
                @endforeach
            </div>
        </div>
    @endif

    <p class="text-[11px] text-stone-400">Sumber: pipeline (terlambat/hari ini/besok/tanpa aksi), sampel tertahan, pembayaran deal, deadline posting, affiliate berhenti.</p>
</div>
@endsection
