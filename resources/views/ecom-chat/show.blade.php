@extends('layouts.app')
@section('title', 'Percakapan')
@section('heading', $conversation->buyer_name ?? 'Percakapan')

@section('content')
@php($statusLabel = [
    'UNPAID' => 'Belum bayar', 'AWAITING_SHIPMENT' => 'Menunggu dikirim',
    'AWAITING_COLLECTION' => 'Menunggu kurir', 'IN_TRANSIT' => 'Dikirim',
    'DELIVERED' => 'Terkirim', 'COMPLETED' => 'Selesai', 'CANCELLED' => 'Dibatalkan',
])
<div class="max-w-2xl">
    @if(session('status'))
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-xl px-4 py-2 mb-4">{{ session('status') }}</div>
    @endif
    <a href="{{ route('ecom-chat.index') }}" class="text-xs text-stone-500 hover:text-stone-800">&larr; Kembali ke inbox</a>

    @php($buyerName = $conversation->buyer_name ?: 'Pembeli')
    <div class="bg-white border border-stone-200 rounded-2xl p-4 my-4 space-y-3">
        @forelse($conversation->messages as $m)
            @php($mine = $m->sender !== 'buyer')
            @php($isCard = in_array($m->type, ['order_card', 'logistics_card'], true))
            @php($isOther = $m->type === 'other')
            <div class="flex items-end gap-2 {{ $mine ? 'flex-row-reverse' : '' }}">
                <span class="shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-[11px] font-bold uppercase {{ $mine ? 'bg-stone-300 text-stone-700' : 'bg-gradient-to-br from-red-500 to-rose-600 text-white' }}">
                    {{ mb_substr($mine ? 'Toko' : $buyerName, 0, 1) }}
                </span>
                <div class="max-w-[75%] min-w-0">
                    @if($isCard)
                        @php($oid = $m->meta['order_id'] ?? null)
                        @php($ord = $oid ? ($orders[$oid] ?? null) : null)
                        <div class="px-3 py-2 rounded-xl border border-stone-300 bg-white text-sm w-64 max-w-full">
                            <p class="font-semibold text-stone-700">{{ $m->type === 'logistics_card' ? '🚚 Info Pengiriman' : '🧾 Pesanan' }}</p>
                            @if($ord)
                                <div class="mt-1 space-y-0.5">
                                    @foreach(($ord->line_items ?? []) as $li)
                                        <p class="text-xs text-stone-600 truncate">{{ ($li['name'] ?? '') ?: ($li['sku'] ?? '—') }} <span class="text-stone-400">×{{ $li['qty'] ?? 1 }}</span></p>
                                    @endforeach
                                </div>
                                <div class="flex items-center justify-between mt-1.5 pt-1.5 border-t border-stone-100">
                                    <span class="font-bold text-stone-800">Rp{{ number_format((float) $ord->total_amount, 0, ',', '.') }}</span>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-stone-100 text-stone-600">{{ $statusLabel[$ord->status] ?? $ord->status }}</span>
                                </div>
                            @else
                                <p class="text-[11px] text-stone-400 mt-0.5">Order belum tersinkron di SKINKU</p>
                            @endif
                            @if($oid)<p class="text-[10px] text-stone-400 mt-1">#{{ $oid }}</p>@endif
                        </div>
                    @elseif($isOther)
                        <div class="px-3 py-1.5 rounded-xl bg-stone-50 border border-dashed border-stone-300 text-stone-400 text-xs italic">{{ $m->text }}</div>
                    @else
                        <div class="px-3 py-2 rounded-2xl text-sm whitespace-pre-line break-words {{ $mine ? 'bg-red-600 text-white' : 'bg-stone-100 text-stone-800' }}">{{ $m->text }}</div>
                    @endif
                    <div class="text-[9px] text-stone-400 mt-0.5 {{ $mine ? 'text-right' : '' }}">
                        {{ $mine ? ($m->via === 'ai' ? '🤖 AI SKINKU' : 'Toko') : $buyerName }} · {{ optional($m->sent_at)->format('d M H:i') }}
                    </div>
                </div>
            </div>
        @empty
            <p class="text-sm text-stone-400 text-center py-4">Belum ada pesan. Klik "🔄 Tarik chat dari TikTok" di inbox untuk mengambil riwayat.</p>
        @endforelse
    </div>

    @if($conversation->ai_reason)
        <p class="text-[11px] text-stone-400 mb-2">Penilaian AI: <b>{{ $conversation->ai_decision }}</b> — {{ $conversation->ai_reason }}</p>
    @endif

    <form method="POST" action="{{ route('ecom-chat.send', $conversation) }}" class="bg-white border border-stone-200 rounded-2xl p-4">
        @csrf
        <label class="block text-xs font-semibold text-stone-600 mb-1">Draft balasan (boleh diedit sebelum kirim)</label>
        <textarea name="text" rows="4" maxlength="4000" class="block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">{{ old('text', $conversation->ai_draft) }}</textarea>
        <div class="flex items-center gap-2 mt-3">
            <button class="px-5 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold">Kirim</button>
        </div>
    </form>

    <form method="POST" action="{{ route('ecom-chat.redraft', $conversation) }}" class="mt-2">
        @csrf
        <button class="text-xs text-stone-500 hover:text-stone-800 underline">Buat ulang draft</button>
    </form>
</div>
@endsection
