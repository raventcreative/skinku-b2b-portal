@php
    // Badge status; untuk "replied" bedakan sumber balasan: AI vs staf.
    $badgeFor = function ($status, $via) {
        if ($status === 'replied') {
            return $via === 'ai'
                ? ['🤖 Dibalas AI', 'bg-violet-100 text-violet-800']
                : ['Dibalas staf', 'bg-emerald-100 text-emerald-800'];
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
        'ditandai' => ['⭐ Ditandai', $flaggedCount],
        'terbalas' => ['Terbalas', null],
        'ditutup' => ['Ditutup', null],
        'semua' => ['Semua', null],
    ];
    $chanTabs = [
        'tiktok' => ['🎵 TikTok', $tiktokPerlu],
        'shopee' => ['🛍️ Shopee', $shopeePerlu],
    ];
@endphp

{{-- Toolbar: Tarik chat + kill-switch AI (per channel). Ada DI DALAM partial yang
     di-swap AJAX supaya label & status ikut benar saat ganti channel. --}}
<div class="p-3 shrink-0">
    <div class="flex items-center gap-2">
        <form method="POST" action="{{ route('ecom-chat.sync') }}" class="flex-1" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Menarik…';">
            @csrf
            <input type="hidden" name="channel" value="{{ $channel }}">
            <button class="w-full px-3 py-2 text-xs font-semibold rounded-lg bg-stone-800 text-white hover:bg-stone-900">🔄 Tarik chat {{ $channel === 'shopee' ? 'Shopee' : 'TikTok' }}</button>
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
            <a href="{{ route('ecom-chat.index', ['channel' => $ch, 'tab' => $tab]) }}" data-channel="{{ $ch }}" class="flex-1 text-center px-2 py-1.5 text-xs font-bold rounded-lg flex items-center justify-center gap-1 {{ $channel === $ch ? 'bg-stone-800 text-white' : 'bg-stone-100 text-stone-500 hover:bg-stone-200' }}">
                {{ $clabel }}
                @if($ccount)<span class="px-1.5 rounded-full text-[10px] {{ $channel === $ch ? 'bg-white/25' : 'bg-red-100 text-red-700' }}">{{ $ccount }}</span>@endif
            </a>
        @endforeach
    </div>
</div>

<div class="px-3 pb-3 border-b border-stone-200 shrink-0">
    <div class="flex flex-wrap gap-1">
        @foreach($tabs as $key => [$tlabel, $tcount])
            <a href="{{ route('ecom-chat.index', ['tab' => $key, 'channel' => $channel]) }}" data-tab="{{ $key }}" class="px-2.5 py-1.5 text-xs font-semibold rounded-lg flex items-center gap-1 {{ $tab === $key ? 'bg-red-600 text-white' : 'bg-stone-100 text-stone-600 hover:bg-stone-200' }}">
                {{ $tlabel }}
                @if($tcount)<span class="px-1.5 rounded-full text-[10px] {{ $tab === $key ? 'bg-white/25' : 'bg-red-100 text-red-700' }}">{{ $tcount }}</span>@endif
            </a>
        @endforeach
    </div>
</div>

<div class="flex-1 overflow-y-auto divide-y divide-stone-100 min-h-0">
    @forelse($conversations as $c)
        @php([$label, $cls] = $badgeFor($c->status, $c->last_reply_via))
        @php($nama = $c->buyer_name ?: ($c->channel === 'shopee' ? 'Pembeli Shopee' : 'Pembeli TikTok'))
        <button type="button" data-conv-id="{{ $c->id }}" onclick="ecomOpen(this)" class="w-full text-left flex items-center gap-3 p-3 hover:bg-stone-50">
            <span class="shrink-0 w-9 h-9 rounded-full bg-gradient-to-br from-red-500 to-rose-600 text-white flex items-center justify-center text-xs font-bold uppercase">{{ mb_substr($nama, 0, 1) }}</span>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-stone-800 truncate">
                    <span data-conv-flag class="text-amber-500 {{ $c->flagged ? '' : 'hidden' }}">★</span>{{ $nama }}
                </p>
                <p class="text-xs text-stone-500 truncate">{{ $c->last_message_preview ?: '—' }}</p>
            </div>
            <div class="flex flex-col items-end gap-1 shrink-0">
                <span data-conv-badge class="text-[9px] font-bold px-1.5 py-0.5 rounded-full {{ $cls }} whitespace-nowrap">{{ $label }}</span>
                <span class="text-[9px] text-stone-400 whitespace-nowrap">{{ optional($c->last_message_at)->diffForHumans(null, true) }}</span>
            </div>
        </button>
    @empty
        <p class="p-6 text-sm text-stone-400 text-center">
            @if($tab === 'perlu')
                Tak ada chat {{ $channel === 'shopee' ? 'Shopee' : 'TikTok' }} yang perlu dibalas 🎉 <a href="{{ route('ecom-chat.index', ['tab' => 'semua', 'channel' => $channel]) }}" data-tab="semua" class="text-red-600 underline">Lihat semua</a>
            @elseif($tab === 'ditandai')
                Belum ada chat yang ditandai. Buka chat lalu klik <b>☆ Tandai</b>.
            @else
                Belum ada percakapan {{ $channel === 'shopee' ? 'Shopee' : 'TikTok' }}. Klik <b>🔄 Tarik chat</b> di atas.
            @endif
        </p>
    @endforelse
</div>
