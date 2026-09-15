@extends('layouts.app')
@section('title', 'Percakapan')
@section('heading', $conversation->buyer_name ?? 'Percakapan')

@section('content')
<div class="max-w-2xl">
    @if(session('status'))
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-xl px-4 py-2 mb-4">{{ session('status') }}</div>
    @endif
    <a href="{{ route('ecom-chat.index') }}" class="text-xs text-stone-500 hover:text-stone-800">&larr; Kembali ke inbox</a>

    <div class="bg-white border border-stone-200 rounded-2xl p-4 my-4 space-y-2">
        @foreach($conversation->messages as $m)
            @php($mine = $m->sender !== 'buyer')
            <div class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
                <div class="max-w-[80%] px-3 py-2 rounded-2xl text-sm {{ $mine ? 'bg-red-600 text-white' : 'bg-stone-100 text-stone-800' }}">
                    {{ $m->text }}
                    @if($mine)
                        <span class="block text-[9px] opacity-70 mt-0.5">{{ $m->via === 'ai' ? 'AI otomatis' : 'Dikirim staf' }}</span>
                    @endif
                </div>
            </div>
        @endforeach
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
