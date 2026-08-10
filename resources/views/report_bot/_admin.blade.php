{{-- Report Bot Telegram: kode akses global + daftar chat yang sudah aktif.
     Data ($reportBot) disiapkan SettingController::index(). --}}
<div class="bg-white rounded-2xl border border-stone-200 p-6 mt-6">
    <div class="flex flex-wrap items-center gap-3 mb-3">
        <h3 class="text-sm font-bold text-stone-900">Report Bot Telegram</h3>
        <form method="POST" action="{{ route('report-bot.rotate') }}" class="ml-auto"
            onsubmit="return confirm('Ganti kode akses sekarang? Chat yang SUDAH aktif tidak terpengaruh — hanya dibutuhkan untuk chat baru.');">
            @csrf
            <button class="px-3 py-1.5 text-xs bg-stone-800 text-white rounded-lg hover:bg-stone-900">⟳ Rotasi Kode</button>
        </form>
    </div>

    <p class="text-xs text-stone-500 mb-3">Kode akses dibagikan ke user yang boleh memakai bot laporan lewat Telegram. Siapa pun yang tahu kode ini bisa membuka chat baru — rotasi bila kode bocor.</p>

    <div class="flex items-center gap-2 mb-5">
        <span class="text-[11px] font-semibold text-stone-500 uppercase tracking-wide">Kode saat ini</span>
        @if($reportBot['access_code'])
            <code class="px-2 py-1 bg-stone-100 rounded-lg text-sm font-mono font-bold text-stone-800">{{ $reportBot['access_code'] }}</code>
        @else
            <span class="px-2 py-1 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-xs">Belum diset — klik "Rotasi Kode" untuk membuatnya.</span>
        @endif
    </div>

    <h4 class="text-xs font-bold text-stone-700 mb-2">Chat Aktif ({{ count($reportBot['chats']) }})</h4>

    @if(count($reportBot['chats']))
        <div class="divide-y divide-stone-100">
            @foreach($reportBot['chats'] as $chat)
                <div class="flex flex-wrap items-center gap-3 py-2 text-xs">
                    <span class="font-semibold text-stone-800">{{ $chat->name ?: '(tanpa nama)' }}</span>
                    <span class="text-stone-400 font-mono">{{ $chat->chat_id }}</span>
                    <span class="text-stone-400">aktif sejak {{ $chat->authorized_at?->format('d M Y H:i') }}</span>

                    @if($chat->is_blocked)
                        <span class="ml-auto px-2 py-1 rounded-lg bg-rose-50 text-rose-700 border border-rose-200 font-semibold">Diblokir</span>
                    @else
                        <form method="POST" action="{{ route('report-bot.chat.revoke', $chat) }}" class="ml-auto"
                            onsubmit="return confirm('Cabut akses chat ini? Chat harus mengetik ulang kode akses untuk bisa memakai bot lagi.');">
                            @csrf
                            <button class="px-3 py-1 text-[11px] bg-rose-600 text-white rounded-lg hover:bg-rose-700 font-semibold">Cabut</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @else
        <p class="text-xs text-stone-400">Belum ada chat yang aktif.</p>
    @endif
</div>
