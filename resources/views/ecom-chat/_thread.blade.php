@php($statusLabel = [
    'UNPAID' => 'Belum bayar', 'AWAITING_SHIPMENT' => 'Menunggu dikirim',
    'AWAITING_COLLECTION' => 'Menunggu kurir', 'IN_TRANSIT' => 'Dikirim',
    'DELIVERED' => 'Terkirim', 'COMPLETED' => 'Selesai', 'CANCELLED' => 'Dibatalkan',
])
@php($buyerName = $conversation->buyer_name ?: 'Pembeli')
@php($isClosed = $conversation->status === 'closed')
@php($isInboxThread = request()->routeIs('ecom-chat.thread', 'ecom-chat.send', 'ecom-chat.redraft', 'ecom-chat.close', 'ecom-chat.reopen', 'ecom-chat.flag'))
{{-- Penanda status/sumber-balasan/tanda untuk sinkronkan daftar kiri tanpa reload. --}}
<span data-thread-status="{{ $conversation->status }}" data-thread-via="{{ $conversation->last_reply_via }}" data-thread-flagged="{{ $conversation->flagged ? '1' : '0' }}" hidden></span>
<div class="flex flex-col h-full min-h-0">
    {{-- header --}}
    <div class="px-4 py-3 border-b border-stone-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 shrink-0">
        <div class="flex items-center gap-2 min-w-0">
            @if($isInboxThread)
                <button type="button" data-inbox-back class="inline-flex min-h-10 shrink-0 items-center gap-1.5 rounded-lg px-2.5 text-xs font-semibold text-brand-maroon hover:bg-brand-cream focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-maroon lg:hidden">
                    <svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12.5 4.5 7 10l5.5 5.5M7.5 10H17"/></svg>
                    Kembali ke inbox
                </button>
            @endif
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-brand-cream text-xs font-bold uppercase text-brand-maroon">{{ mb_substr($buyerName, 0, 1) }}</span>
            <div class="min-w-0">
                <p class="truncate text-sm font-bold text-stone-800">{{ $buyerName }}</p>
                <span class="inline-flex rounded-full bg-brand-cream px-2 py-0.5 text-[10px] font-semibold text-brand-maroon">{{ $conversation->channel === 'shopee' ? 'Shopee' : 'TikTok' }}</span>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-1.5 self-start sm:self-auto">
            <form method="POST" action="{{ route('ecom-chat.flag', $conversation) }}" data-flag>
                @csrf
                <button type="submit" class="inline-flex min-h-8 items-center gap-1.5 rounded-lg border px-2.5 text-[11px] font-semibold whitespace-nowrap focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600 {{ $conversation->flagged ? 'border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100' : 'border-stone-200 bg-brand-cream text-stone-600 hover:bg-stone-50' }}" title="Tandai untuk prioritas">
                    <svg aria-hidden="true" focusable="false" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="{{ $conversation->flagged ? 'currentColor' : 'none' }}"><path d="m10 2.5 2.2 4.45 4.9.71-3.55 3.46.84 4.88L10 13.7l-4.39 2.3.84-4.88L2.9 7.66l4.9-.71L10 2.5Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                    {{ $conversation->flagged ? 'Ditandai' : 'Tandai' }}
                </button>
            </form>
            <form method="POST" action="{{ route('ecom-chat.redraft', $conversation) }}" data-redraft>
                @csrf
                <button type="submit" class="inline-flex min-h-8 items-center gap-1.5 rounded-lg border border-stone-200 bg-brand-cream px-2.5 text-[11px] font-semibold text-stone-700 hover:bg-stone-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-stone-500 whitespace-nowrap" title="Buat ulang draft AI">
                    <svg aria-hidden="true" focusable="false" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 9a6.5 6.5 0 0 0-11.8-3L3 8m0 0V4m0 4h4m-3.5 3a6.5 6.5 0 0 0 11.8 3L17 12m0 0v4m0-4h-4"/></svg>
                    Buat ulang draft AI
                </button>
            </form>
            @if($isClosed)
                <form method="POST" action="{{ route('ecom-chat.reopen', $conversation) }}" data-close>
                    @csrf
                    <button type="submit" class="inline-flex min-h-8 items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 text-[11px] font-semibold text-emerald-800 hover:bg-emerald-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 whitespace-nowrap" title="Buka lagi">
                        <svg aria-hidden="true" focusable="false" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7V3m0 4h4m-4 0a6.5 6.5 0 1 1-.2 6"/></svg>
                        Buka lagi
                    </button>
                </form>
            @else
                <form method="POST" action="{{ route('ecom-chat.close', $conversation) }}" data-close>
                    @csrf
                    <button type="submit" class="inline-flex min-h-8 items-center gap-1.5 rounded-lg border border-stone-200 bg-brand-cream px-2.5 text-[11px] font-semibold text-stone-600 hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-700 whitespace-nowrap" title="Tutup chat">
                        <svg aria-hidden="true" focusable="false" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 20 20"><path stroke-linecap="round" d="m5 5 10 10M15 5 5 15"/></svg>
                        Tutup chat
                    </button>
                </form>
            @endif
        </div>
    </div>

    {{-- messages --}}
    <div id="threadMessages" class="flex-1 overflow-y-auto p-4 space-y-3 min-h-0 bg-stone-50/40">
        @forelse($conversation->messages as $m)
            @php($mine = $m->sender !== 'buyer')
            @php($isCard = in_array($m->type, ['order_card', 'logistics_card'], true))
            @php($isBot = $m->via === 'bot')
            @php($isOther = $m->type === 'other' && str_starts_with((string) $m->text, '📎'))
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
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-[11px] font-bold uppercase {{ ! $mine ? 'bg-brand-maroon text-white' : ($isBot ? 'bg-sky-100 text-sky-700' : ($m->via === 'ai' ? 'bg-violet-100 text-violet-700' : 'bg-stone-200 text-stone-700')) }}">
                    {{ ! $mine ? mb_substr($buyerName, 0, 1) : ($isBot ? 'B' : ($m->via === 'ai' ? 'AI' : 'S')) }}
                </span>
                <div class="max-w-[75%] min-w-0">
                    @if($isCard)
                        @php($oid = $m->meta['order_id'] ?? null)
                        @php($ord = $oid ? ($orders[$oid] ?? null) : null)
                        <div class="px-3 py-2 rounded-xl border border-stone-300 bg-brand-cream text-sm w-64 max-w-full">
                            <p class="font-semibold text-stone-700">{{ $m->type === 'logistics_card' ? 'Info Pengiriman' : 'Pesanan' }}</p>
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
                            <span class="text-[11px] text-sky-600 underline"> Foto{{ $mediaUrl ? ' — buka' : '' }}</span>
                        </a>
                    @elseif($isVideo)
                        <a href="{{ $mediaUrl ?: '#' }}" target="_blank" rel="noopener" class="text-[11px] text-sky-600 underline"> Video — buka</a>
                    @elseif($isProduct)
                        @php($prod = $productId ? (($products ?? collect())[$productId] ?? null) : null)
                        <div class="rounded-xl border border-stone-300 bg-brand-cream text-sm w-56 max-w-full overflow-hidden">
                            @if($prod && $prod->image_url)
                                <img src="{{ $prod->image_url }}" alt="" class="w-full h-32 object-cover" loading="lazy" onerror="this.remove()">
                            @endif
                            <div class="px-3 py-2">
                                <p class="text-[10px] text-stone-400">Produk ditanya pembeli</p>
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
                                    <a href="{{ $prodUrl }}" target="_blank" rel="noopener" class="text-[11px] text-sky-600 underline">Buka produk </a>
                                @endif
                            </div>
                        </div>
                    @elseif($isOther)
                        <div class="px-3 py-1.5 rounded-xl bg-stone-50 border border-dashed border-stone-300 text-stone-400 text-xs italic">{{ $m->text }}</div>
                    @elseif(trim((string) $m->text) === '')
                        <div class="px-3 py-1.5 rounded-xl bg-stone-50 border border-dashed border-stone-300 text-stone-400 text-xs italic">(pesan kosong / tipe tak dikenal)</div>
                    @elseif($isBot)
                        <div class="px-3 py-2 rounded-2xl text-sm whitespace-pre-line wrap-break-word bg-sky-50 border border-sky-200 text-sky-900">{{ $m->text }}</div>
                    @else
                        <div class="px-3 py-2 rounded-2xl text-sm whitespace-pre-line wrap-break-word {{ $mine ? 'bg-brand-maroon text-white' : 'bg-brand-cream border border-stone-200 text-stone-800' }}">{{ $m->text }}</div>
                    @endif
                    <div class="text-[9px] text-stone-400 mt-0.5 {{ $mine ? 'text-right' : '' }}">
                        @if($mine)
                            @php([$vLabel, $vCls] = match ($m->via) {
                                'ai' => ['AI SKINKU', 'bg-violet-100 text-violet-700'],
                                'bot' => ['Bot TikTok', 'bg-sky-100 text-sky-700'],
                                default => ['Staf', 'bg-stone-200 text-stone-600'],
                            })
                            <span class="inline-block px-1.5 py-px rounded font-semibold {{ $vCls }}">{{ $vLabel }}</span>
                        @else
                            {{ $buyerName }}
                        @endif
                        · {{ optional($m->sent_at)->format('d M H:i') }}
                    </div>
                </div>
            </div>
        @empty
            <p class="text-sm text-stone-400 text-center py-6">Belum ada pesan pada percakapan ini.</p>
        @endforelse
    </div>

    {{-- composer --}}
    @php($handled = in_array($conversation->status, ['replied', 'closed'], true))
    <form method="POST" action="{{ route('ecom-chat.send', $conversation) }}" data-send class="border-t border-stone-200 p-3 shrink-0 bg-brand-cream">
        @csrf
        {{-- Draft AI hanya diisikan bila chat MASIH perlu ditangani; yang sudah dibalas → kosong. --}}
        <textarea name="text" rows="2" maxlength="4000" placeholder="Tulis balasan… (Enter = kirim, Shift+Enter = baris baru)" class="block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm resize-none">{{ old('text', $handled ? '' : $conversation->ai_draft) }}</textarea>
        <div class="flex flex-wrap items-center gap-2 mt-2">
            @if($conversation->ai_reason && ! $handled)
                <span class="text-[10px] text-stone-400 truncate">AI: <b>{{ $conversation->ai_decision }}</b> — {{ $conversation->ai_reason }}</span>
            @endif
            <span class="text-[10px] text-stone-300 ml-auto mr-1 hidden sm:inline">Enter ⏎ kirim</span>
            <button type="submit" data-send-btn class="inline-flex min-h-10 items-center gap-2 rounded-xl bg-brand-maroon px-5 py-2 text-sm font-semibold text-white hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-maroon focus-visible:ring-offset-2"><svg aria-hidden="true" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m21 3-7.5 18-3.8-7.7L2 9.5 21 3Zm0 0L9.7 13.3"/></svg>Kirim</button>
        </div>
    </form>
</div>
