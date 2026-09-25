@extends('layouts.app')
@section('title', 'Percakapan')
@section('heading', $conversation->buyer_name ?: 'Percakapan')

@section('content')
<div class="max-w-2xl">
    @if(session('status'))
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-xl px-4 py-2 mb-3">{{ session('status') }}</div>
    @endif
    <a href="{{ route('ecom-chat.index') }}" class="text-xs text-stone-500 hover:text-stone-800">&larr; Kembali ke inbox</a>

    <div class="mt-3 bg-white border border-stone-200 rounded-2xl overflow-hidden h-[calc(100vh-13rem)] min-h-96">
        @include('ecom-chat._thread')
    </div>
</div>

<script>
(function () {
    var t = document.getElementById('threadMessages');
    if (t) t.scrollTop = t.scrollHeight;
})();
</script>
@endsection
