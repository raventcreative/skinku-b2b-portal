@extends('layouts.app')
@section('title', 'Reminder KOL')
@section('heading', 'Reminder KOL')

@section('content')
<div class="max-w-4xl space-y-4">

    <p class="text-sm text-stone-500">Yang mendesak dari pipeline — kerjakan dari atas.</p>

    <div class="flex flex-wrap gap-2">
        <span class="text-xs px-3 py-1 rounded-full {{ $lateCount ? 'bg-rose-100 text-rose-700' : 'bg-stone-100 text-stone-500' }}">{{ $lateCount }} terlambat</span>
        <span class="text-xs px-3 py-1 rounded-full {{ $dueCount ? 'bg-amber-100 text-amber-700' : 'bg-stone-100 text-stone-500' }}">{{ $dueCount }} jatuh tempo hari ini</span>
        <span class="text-xs px-3 py-1 rounded-full {{ $noneCount ? 'bg-amber-100 text-amber-700' : 'bg-stone-100 text-stone-500' }}">{{ $noneCount }} tanpa next action</span>
    </div>

    @if($rows->isEmpty())
        <div class="px-4 py-10 rounded-2xl border border-dashed border-stone-300 text-center text-sm text-stone-500">
            Tidak ada yang mendesak. 🎉
        </div>
    @else
        <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden divide-y divide-stone-100">
            @foreach($rows as $c)
                @php $late = $c->next_action_at && $c->next_action_at->lt($today); @endphp
                <div class="flex items-center justify-between gap-3 px-4 py-3">
                    <div class="min-w-0">
                        <a href="{{ route('kols.show', $c->kol_id) }}" class="text-sm font-semibold text-indigo-600 hover:underline">{{ '@'.$c->kol->tiktok_username }}</a>
                        <span class="ml-2 text-[10px] uppercase tracking-wide text-stone-400">{{ \App\Models\KolPipelineCard::STAGE_LABELS[$c->stage] }}</span>
                        <p class="text-xs text-stone-500 truncate">{{ $c->next_action ?? '— belum ada next action —' }}</p>
                    </div>
                    <div class="text-right shrink-0">
                        @if($c->next_action_at)
                            <p class="text-xs {{ $late ? 'text-rose-600 font-medium' : 'text-stone-500' }}">{{ $c->next_action_at->format('d M Y') }}</p>
                            @if($late)
                                <p class="text-[10px] text-rose-500">terlambat {{ (int) $c->next_action_at->diffInDays($today) }} hari</p>
                            @endif
                        @else
                            <p class="text-[10px] text-amber-600">tanpa tanggal</p>
                        @endif
                        <a href="{{ route('kol-pipeline.index') }}#card-{{ $c->id }}" class="text-[11px] text-indigo-600 hover:underline">Buka →</a>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Tagihan deal belum lunas (finance) --}}
    @if($payments->isNotEmpty())
        <div class="pt-2">
            <p class="text-sm font-semibold text-stone-700 mb-2">💸 Pembayaran deal belum lunas</p>
            <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden divide-y divide-stone-100">
                @foreach($payments as $d)
                    <div class="flex items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <a href="{{ route('kol-deals.edit', $d) }}" class="text-sm font-semibold text-indigo-600 hover:underline">{{ '@'.$d->kol->tiktok_username }}</a>
                            <span class="ml-2 text-[10px] uppercase tracking-wide text-stone-400">{{ $d->kode }} · bayar {{ $d->status_bayar }}</span>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="text-xs font-medium text-stone-700">Rp {{ number_format((float) $d->total_biaya, 0, ',', '.') }}</p>
                            <p class="text-[10px] text-stone-400">{{ $d->periode_selesai?->format('d M Y') ?? 'tanpa tenggat' }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Deadline posting: deal berjalan tanpa konten --}}
    @if($postingDue->isNotEmpty())
        <div class="pt-2">
            <p class="text-sm font-semibold text-stone-700 mb-2">📹 Deal berjalan belum ada konten</p>
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

    {{-- Affiliate berhenti posting --}}
    @if($churn->isNotEmpty())
        <div class="pt-2">
            <p class="text-sm font-semibold text-stone-700 mb-2">💤 Affiliate berhenti posting (≥ 2 minggu)</p>
            <div class="bg-white rounded-2xl border border-stone-200 p-3 flex flex-wrap gap-2">
                @foreach($churn as $k)
                    <a href="{{ route('kols.show', $k->id) }}" class="text-xs px-3 py-1.5 rounded-full bg-stone-100 text-stone-600 hover:bg-stone-200">{{ '@'.$k->tiktok_username }}</a>
                @endforeach
            </div>
        </div>
    @endif

    <p class="text-[11px] text-stone-400">Reminder tarik dari: pipeline, pembayaran deal, deadline posting deal, dan affiliate berhenti posting.</p>
</div>
@endsection
