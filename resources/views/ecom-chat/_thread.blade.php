@php($statusLabel = [
    'UNPAID' => 'Belum bayar', 'AWAITING_SHIPMENT' => 'Menunggu dikirim',
    'AWAITING_COLLECTION' => 'Menunggu kurir', 'IN_TRANSIT' => 'Dikirim',
    'DELIVERED' => 'Terkirim', 'COMPLETED' => 'Selesai', 'CANCELLED' => 'Dibatalkan',
])
@php($buyerName = $conversation->buyer_name ?: 'Pembeli')
@php($isClosed = $conversation->status === 'closed')
{{-- Penanda status/sumber-balasan untuk sinkronkan tag di daftar kiri tanpa reload. --}}
<span data-thread-status="{{ $conversation->status }}" data-thread-via="{{ $conversation->last_reply_via }}" hidden></span>
<div class="flex flex-col h-full min-h-0">
    {{-- header --}}
    <div class="px-4 py-3 border-b border-stone-200 flex items-center justify-between gap-3 shrink-0">
        <div class="flex items-center gap-2 min-w-0">
            <span class="shrink-0 w-8 h-8 rounded-full bg-gradient-to-br from-red-500 to-rose-600 text-white flex items-center justify-center text-xs font-bold uppercase">{{ mb_substr($buyerName, 0, 1) }}</span>
            <span class="font-bold text-stone-800 truncate">{{ $buyerName }}</span>
        </div>
        <div class="flex items-center gap-3 shrink-0">
            <form method="POST" action="{{ route('ecom-chat.redraft', $conversation) }}" data-redraft>
                @csrf
                <button type="submit" class="text-xs text-stone-500 hover:text-stone-800 whitespace-nowrap">↻ Buat ulang draft AI</button>
            </form>
            @if($isClosed)
                <form method="POST" action="{{ route('ecom-chat.reopen', $conversation) }}" data-close>
                    @csrf
                    <button type="submit" class="text-xs font-semibold text-emerald-600 hover:text-emerald-700 whitespace-nowrap">↩ Buka lagi</button>
                </form>
            @else
                <form method="POST" action="{{ route('ecom-chat.close', $conversation) }}" data-close>
                    @csrf
                    <button type="submit" class="text-xs font-semibold text-stone-500 hover:text-rose-600 whitespace-nowrap">✓ Tutup chat</button>
                </form>
            @endif
        </div>
    </div>

    {{-- messages --}}
    <div id="threadMessages" class="flex-1 overflow-y-auto p-4 space-y-3 min-h-0 bg-stone-50/40">
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
                        <div class="px-3 py-2 rounded-2xl text-sm whitespace-pre-line break-words {{ $mine ? 'bg-red-600 text-white' : 'bg-white border border-stone-200 text-stone-800' }}">{{ $m->text }}</div>
                    @endif
                    <div class="text-[9px] text-stone-400 mt-0.5 {{ $mine ? 'text-right' : '' }}">
                        {{ $mine ? ($m->via === 'ai' ? '🤖 AI SKINKU' : 'Toko') : $buyerName }} · {{ optional($m->sent_at)->format('d M H:i') }}
                    </div>
                </div>
            </div>
        @empty
            <p class="text-sm text-stone-400 text-center py-6">Belum ada pesan pada percakapan ini.</p>
        @endforelse
    </div>

    {{-- composer --}}
    <form method="POST" action="{{ route('ecom-chat.send', $conversation) }}" data-send class="border-t border-stone-200 p-3 shrink-0 bg-white">
        @csrf
        <textarea name="text" rows="2" maxlength="4000" placeholder="Tulis balasan…" class="block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm resize-none">{{ old('text', $conversation->ai_draft) }}</textarea>
        <div class="flex items-center gap-2 mt-2">
            @if($conversation->ai_reason)
                <span class="text-[10px] text-stone-400 truncate">AI: <b>{{ $conversation->ai_decision }}</b> — {{ $conversation->ai_reason }}</span>
            @endif
            <button type="submit" data-send-btn class="ml-auto px-5 py-2 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold">Kirim</button>
        </div>
    </form>
</div>
