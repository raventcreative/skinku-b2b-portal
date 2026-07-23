@extends('layouts.app')
@section('title', 'Asisten AI')
@section('heading', 'Asisten AI')

@section('content')
<div class="max-w-3xl">
    <div class="flex items-center justify-between mb-3">
        <p class="text-xs text-stone-500">Asisten internal — bisa baca ringkasan dashboard & bantu buat kartu Kanban (aksi tulis selalu minta konfirmasi).</p>
        @if(!empty($thread))
            <form method="POST" action="{{ route('ai.reset') }}" onsubmit="return confirm('Mulai percakapan baru? Riwayat sekarang dihapus.')">
                @csrf
                <button class="text-[11px] text-stone-400 hover:text-rose-600">Mulai baru</button>
            </form>
        @endif
    </div>

    {{-- Percakapan --}}
    <div class="bg-stone-50 border border-stone-200 rounded-2xl p-4 space-y-3 min-h-[16rem]">
        @forelse($thread as $msg)
            @if($msg['role'] === 'user')
                <div class="flex justify-end">
                    <div class="max-w-[80%] px-3 py-2 rounded-2xl rounded-br-sm bg-red-600 text-white text-sm whitespace-pre-line">{{ $msg['content'] }}</div>
                </div>
            @else
                <div class="flex justify-start">
                    <div class="max-w-[85%] px-3 py-2 rounded-2xl rounded-bl-sm bg-white border border-stone-200 text-sm text-stone-800">{!! nl2br(e($msg['content'])) !!}</div>
                </div>
            @endif
        @empty
            <div class="text-center text-stone-400 text-sm py-10">
                <p class="font-semibold text-stone-500">Halo! 👋 Aku bisa bantu apa?</p>
                <p class="mt-2 text-xs">Contoh: <em>“ringkas penjualan bulan ini”</em> · <em>“gimana stok yang menipis?”</em> ·
                <em>“buatkan kartu Kanban ‘revisi katalog’ di papan X kolom To Do untuk Agatha”</em></p>
            </div>
        @endforelse

        {{-- Kartu konfirmasi aksi tulis (menunggu klik Ya) --}}
        @if($pending)
            <div class="flex justify-start">
                <div class="max-w-[90%] w-full px-4 py-3 rounded-2xl bg-amber-50 border border-amber-200">
                    <p class="text-xs font-semibold text-amber-800 mb-1">Konfirmasi aksi</p>
                    <p class="text-sm text-stone-700">{!! nl2br(e($pending['preview'])) !!}</p>
                    <form method="POST" action="{{ route('ai.confirm') }}" class="flex gap-2 mt-3">
                        @csrf
                        <button name="setuju" value="ya" class="px-4 py-1.5 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700 font-semibold">Ya, jalankan</button>
                        <button name="setuju" value="batal" class="px-4 py-1.5 text-sm text-stone-500 hover:text-stone-800">Batal</button>
                    </form>
                </div>
            </div>
        @endif
    </div>

    {{-- Input --}}
    <form method="POST" action="{{ route('ai.send') }}" class="mt-3 flex items-end gap-2" {{ $pending ? 'hidden' : '' }}>
        @csrf
        <textarea name="message" rows="2" maxlength="2000" required autofocus placeholder="Tulis pertanyaan atau perintah…"
            class="flex-1 px-3 py-2 border border-stone-300 rounded-xl text-sm resize-none focus:outline-none focus:ring-2 focus:ring-red-200"
            onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();this.form.submit();}"></textarea>
        <button class="px-5 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold shrink-0">Kirim</button>
    </form>
    @if($pending)
        <p class="mt-3 text-center text-[11px] text-stone-400">Selesaikan konfirmasi di atas dulu untuk lanjut mengetik.</p>
    @endif
</div>
@endsection
