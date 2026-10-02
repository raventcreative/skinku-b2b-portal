@php
    // Badge status; untuk "replied" bedakan sumber balasan: AI vs staf.
    $badgeFor = function ($status, $via) {
        if ($status === 'replied') {
            return match ($via) {
                'ai' => ['Dibalas AI', 'bg-violet-100 text-violet-800'],
                'bot' => ['Dibalas bot TikTok', 'bg-sky-100 text-sky-800'],
                default => ['Dibalas staf', 'bg-emerald-100 text-emerald-800'],
            };
        }

        return [
            'needs_staff' => ['Perlu staf', 'bg-amber-100 text-amber-800'],
            'open' => ['Baru', 'bg-sky-100 text-sky-800'],
            'closed' => ['Selesai', 'bg-stone-100 text-stone-600'],
        ][$status] ?? ['—', 'bg-stone-100 text-stone-600'];
    };
    $tabs = [
        'perlu' => ['Perlu dibalas', $perluCount],
        'belum_dibaca' => ['Belum dibaca', $unreadCount],
        'ditandai' => ['Ditandai', $flaggedCount],
        'terbalas' => ['Terbalas', null],
        'ditutup' => ['Ditutup', null],
        'semua' => ['Semua', null],
    ];
    $chanTabs = [
        'tiktok' => ['TikTok', $tiktokPerlu],
        'shopee' => ['Shopee', $shopeePerlu],
    ];
@endphp

{{-- Toolbar: Tarik chat + kill-switch AI (per channel). Ada DI DALAM partial yang
     di-swap AJAX supaya label & status ikut benar saat ganti channel. --}}
<div class="p-3 shrink-0">
    <div class="flex items-center gap-2">
        <form method="POST" action="{{ route('ecom-chat.sync') }}" class="flex-1" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Menarik…';">
            @csrf
            <input type="hidden" name="channel" value="{{ $channel }}">
            <button class="w-full min-h-10 px-3 py-2 text-xs font-semibold rounded-lg bg-brand-maroon text-white hover:bg-brand-dark inline-flex items-center justify-center gap-2 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-maroon focus-visible:ring-offset-2"><svg aria-hidden="true" viewBox="0 0 20 20" fill="none" class="w-4 h-4"><path d="M16.5 9a6.5 6.5 0 0 0-11.8-3L3 8m0 0V4m0 4h4m-3.5 3a6.5 6.5 0 0 0 11.8 3L17 12m0 0v4m0-4h-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>Tarik chat {{ $channel === 'shopee' ? 'Shopee' : 'TikTok' }}</button>
        </form>
        <form method="POST" action="{{ route('ecom-chat.autosend') }}">
            @csrf
            <input type="hidden" name="on" value="{{ $autosend ? '0' : '1' }}">
            <input type="hidden" name="channel" value="{{ $channel }}">
            <button class="px-3 py-2 text-xs font-semibold rounded-lg whitespace-nowrap {{ $autosend ? 'bg-emerald-600 text-white' : 'bg-stone-200 text-stone-700' }}" title="Auto-send balasan AI untuk {{ $channel === 'shopee' ? 'Shopee' : 'TikTok' }} (per platform)">
                AI {{ $channel === 'shopee' ? 'Shopee' : 'TikTok' }}: {{ $autosend ? 'ON' : 'OFF' }}
            </button>
        </form>
    </div>
</div>

<div class="px-3 pt-3 pb-2 shrink-0">
    {{-- Tab channel: TikTok / Shopee dipisah (bukan 1 kolom campur). --}}
    <div class="flex gap-1 mb-2">
        @foreach($chanTabs as $ch => [$clabel, $ccount])
            <a href="{{ route('ecom-chat.index', ['channel' => $ch, 'tab' => $tab]) }}" data-channel="{{ $ch }}" @if($channel === $ch) aria-current="page" @endif class="flex-1 min-h-10 text-center px-2 py-2 text-xs font-bold rounded-lg flex items-center justify-center gap-2 transition {{ $channel === $ch ? 'bg-brand-maroon text-white' : 'bg-stone-100 text-stone-600 hover:bg-brand-cream hover:text-brand-maroon' }}">
                {{ $clabel }}
                @if($ccount)<span class="min-w-5 rounded-full px-1.5 py-0.5 text-[10px] tabular-nums {{ $channel === $ch ? 'bg-brand-cream/15 text-white' : 'bg-brand-cream text-brand-maroon' }}">{{ $ccount }}</span>@endif
            </a>
        @endforeach
    </div>
</div>

<div class="px-3 pb-3 border-b border-stone-200 shrink-0">
    <div class="flex flex-wrap gap-1">
        @foreach($tabs as $key => [$tlabel, $tcount])
            <a href="{{ route('ecom-chat.index', ['tab' => $key, 'channel' => $channel]) }}" data-tab="{{ $key }}" @if($tab === $key) aria-current="page" @endif class="min-h-9 px-2.5 py-2 text-xs font-semibold rounded-lg flex items-center gap-1.5 transition {{ $tab === $key ? 'bg-brand-maroon text-white' : 'bg-stone-100 text-stone-600 hover:bg-brand-cream hover:text-brand-maroon' }}">
                {{ $tlabel }}
                @if($tcount)<span class="min-w-5 rounded-full px-1.5 py-0.5 text-[10px] tabular-nums {{ $tab === $key ? 'bg-brand-cream/15 text-white' : 'bg-stone-200 text-stone-700' }}">{{ $tcount }}</span>@endif
            </a>
        @endforeach
    </div>
</div>

<div class="flex-1 overflow-y-auto divide-y divide-stone-100 min-h-0">
    @forelse($conversations as $c)
        @php([$label, $cls] = $badgeFor($c->status, $c->last_reply_via))
        @php($nama = $c->buyer_name ?: ($c->channel === 'shopee' ? 'Pembeli Shopee' : 'Pembeli TikTok'))
        <button type="button" data-conv-id="{{ $c->id }}" aria-pressed="false" onclick="ecomOpen(this)" class="w-full text-left flex min-h-[4.5rem] items-center gap-3 border-l-2 border-transparent p-3 transition hover:bg-brand-cream/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-maroon">
            <span class="shrink-0 grid h-10 w-10 place-items-center rounded-xl bg-brand-maroon text-sm font-bold uppercase text-white">{{ mb_substr($nama, 0, 1) }}</span>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-stone-800 truncate">
                    <span data-conv-flag class="inline-flex align-middle text-brand-gold {{ $c->flagged ? '' : 'hidden' }}"><svg aria-label="Ditandai" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="m10 2.5 2.2 4.45 4.9.71-3.55 3.46.84 4.88L10 13.7l-4.39 2.3.84-4.88L2.9 7.66l4.9-.71L10 2.5Z"/></svg></span>{{ $nama }}
                </p>
                <p class="text-xs text-stone-500 truncate">{{ $c->last_message_preview ?: '—' }}</p>
            </div>
            <div class="flex flex-col items-end gap-1 shrink-0">
                <span data-conv-badge class="text-[10px] font-bold px-2 py-1 rounded-full {{ $cls }} whitespace-nowrap">{{ $label }}</span>
                <span class="text-[9px] text-stone-400 whitespace-nowrap">{{ optional($c->last_message_at)->diffForHumans(null, true) }}</span>
            </div>
        </button>
    @empty
        <p class="p-6 text-sm text-stone-400 text-center">
            @if($tab === 'perlu')
                Tak ada chat {{ $channel === 'shopee' ? 'Shopee' : 'TikTok' }} yang perlu dibalas. <a href="{{ route('ecom-chat.index', ['tab' => 'semua', 'channel' => $channel]) }}" data-tab="semua" class="font-semibold text-brand-maroon underline underline-offset-2">Lihat semua</a>
            @elseif($tab === 'ditandai')
                Belum ada chat yang ditandai. Buka chat lalu pilih <b>Tandai</b>.
            @else
                Belum ada percakapan {{ $channel === 'shopee' ? 'Shopee' : 'TikTok' }}. Klik <b>Tarik chat</b> di atas.
            @endif
        </p>
    @endforelse
</div>
