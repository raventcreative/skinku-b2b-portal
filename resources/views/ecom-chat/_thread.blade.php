@php($statusLabel = [
    'UNPAID' => 'Belum bayar', 'AWAITING_SHIPMENT' => 'Menunggu dikirim',
    'AWAITING_COLLECTION' => 'Menunggu kurir', 'IN_TRANSIT' => 'Dikirim',
    'DELIVERED' => 'Terkirim', 'COMPLETED' => 'Selesai', 'CANCELLED' => 'Dibatalkan',
])
@php($buyerName = $conversation->buyer_name ?: 'Pembeli')
@php($isClosed = $conversation->status === 'closed')
{{-- Penanda status/sumber-balasan/tanda untuk sinkronkan daftar kiri tanpa reload. --}}
<span data-thread-status="{{ $conversation->status }}" data-thread-via="{{ $conversation->last_reply_via }}" data-thread-flagged="{{ $conversation->flagged ? '1' : '0' }}" hidden></span>
<div class="flex flex-col h-full min-h-0">
    {{-- header --}}
    <div class="px-4 py-3 border-b border-stone-200 flex items-center justify-between gap-3 shrink-0">
        <div class="flex items-center gap-2 min-w-0">
            <span class="shrink-0 w-8 h-8 rounded-full bg-gradient-to-br from-red-500 to-rose-600 text-white flex items-center justify-center text-xs font-bold uppercase">{{ mb_substr($buyerName, 0, 1) }}</span>
            <span class="font-bold text-stone-800 truncate">{{ $buyerName }}</span>
        </div>
        <div class="flex items-center gap-3 shrink-0">
            <form method="POST" action="{{ route('ecom-chat.flag', $conversation) }}" data-flag>
                @csrf
                <button type="submit" class="text-xs font-semibold whitespace-nowrap {{ $conversation->flagged ? 'text-amber-500' : 'text-stone-400 hover:text-amber-500' }}" title="Tandai untuk prioritas">
                    {{ $conversation->flagged ? '★ Ditandai' : '☆ Tandai' }}
                </button>
            </form>
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
            @php($isImage = $m->type === 'image')
            @php($isVideo = $m->type === 'video')
            @php($mediaUrl = ($isImage || $isVideo) ? (string) ($m->meta['url'] ?? '') : '')
            {{-- Legacy: pesan lama tersimpan sbg text padahal isinya JSON foto → tampilkan sbg foto. --}}
            @php($__dec = (! $isImage && ! $isVideo && ! $isCard && ! $isOther) ? json_decode((string) $m->text, true) : null)
            @php($__mediaJson = is_array($__dec) && ! empty($__dec['url']) && (isset($__dec['width']) || isset($__dec['height'])))
            @php($isImage = $isImage || $__mediaJson)
            @php($mediaUrl = $__mediaJson ? (string) $__dec['url'] : $mediaUrl)
            @php($__prodJson = is_array($__dec) && ! empty($__dec['product_id']))
            @php($isProduct = $m->type === 'product_card' || $__prodJson)
            @php($productId = $m->type === 'product_card' ? (string) ($m->meta['product_id'] ?? '') : ($__prodJson ? (string) $__dec['product_id'] : ''))
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
                    @elseif($isImage)
                        <a href="{{ $mediaUrl ?: '#' }}" target="_blank" rel="noopener" class="block">
                            @if($mediaUrl)
                                <img src="{{ $mediaUrl }}" alt="Foto dari pembeli" class="max-h-48 rounded-xl border border-stone-200 mb-1" loading="lazy" onerror="this.remove()">
                            @endif
                            <span class="text-[11px] text-sky-600 underline">🖼️ Foto{{ $mediaUrl ? ' — buka' : '' }}</span>
                        </a>
                    @elseif($isVideo)
                        <a href="{{ $mediaUrl ?: '#' }}" target="_blank" rel="noopener" class="text-[11px] text-sky-600 underline">🎬 Video — buka</a>
                    @elseif($isProduct)
                        @php($prod = $productId ? (($products ?? collect())[$productId] ?? null) : null)
                        <div class="rounded-xl border border-stone-300 bg-white text-sm w-56 max-w-full overflow-hidden">
                            @if($prod && $prod->image_url)
                                <img src="{{ $prod->image_url }}" alt="" class="w-full h-32 object-cover" loading="lazy" onerror="this.remove()">
                            @endif
                            <div class="px-3 py-2">
                                <p class="text-[10px] text-stone-400">🛍️ Produk ditanya pembeli</p>
                                @if($prod && $prod->title)
                                    <p class="font-semibold text-stone-800 leading-snug">{{ $prod->title }}</p>
                                    @if($prod->price)<p class="font-bold text-stone-800 mt-0.5">Rp{{ number_format($prod->price, 0, ',', '.') }}</p>@endif
                                @elseif($productId)
                                    <p class="text-[11px] text-stone-500 break-all">ID: {{ $productId }}</p>
                                @endif
                                @php($prodShopId = $m->meta['shop_id'] ?? '')
                                @php($prodUrl = $conversation->channel === 'shopee'
                                    ? 'https://shopee.co.id/product/'.$prodShopId.'/'.$productId
                                    : 'https://shop-id.tokopedia.com/view/product/'.$productId)
                                @if($productId)
                                    <a href="{{ $prodUrl }}" target="_blank" rel="noopener" class="text-[11px] text-sky-600 underline">Buka produk ↗</a>
                                @endif
                            </div>
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
    @php($handled = in_array($conversation->status, ['replied', 'closed'], true))
    <form method="POST" action="{{ route('ecom-chat.send', $conversation) }}" data-send class="border-t border-stone-200 p-3 shrink-0 bg-white">
        @csrf
        {{-- Draft AI hanya diisikan bila chat MASIH perlu ditangani; yang sudah dibalas → kosong. --}}
        <textarea name="text" rows="2" maxlength="4000" placeholder="Tulis balasan… (Enter = kirim, Shift+Enter = baris baru)" class="block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm resize-none">{{ old('text', $handled ? '' : $conversation->ai_draft) }}</textarea>
        <div class="flex items-center gap-2 mt-2">
            @if($conversation->ai_reason && ! $handled)
                <span class="text-[10px] text-stone-400 truncate">AI: <b>{{ $conversation->ai_decision }}</b> — {{ $conversation->ai_reason }}</span>
            @endif
            <span class="text-[10px] text-stone-300 ml-auto mr-1 hidden sm:inline">Enter ⏎ kirim</span>
            <button type="submit" data-send-btn class="px-5 py-2 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold">Kirim</button>
        </div>
    </form>
</div>
