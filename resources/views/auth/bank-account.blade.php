@extends('layouts.app')
@section('title', 'Rekening')
@section('heading', 'Rekening')

@section('content')
<div class="max-w-md">
    <div class="bg-white rounded-2xl border border-stone-200 p-6">
        <h3 class="text-sm font-bold text-stone-900 mb-4">Rekening Bank Anda</h3>

        @if(session('status'))
            <div class="mb-4 px-4 py-2.5 text-sm text-green-800 bg-green-50 border border-green-200 rounded-xl">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('account.rekening') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-xs font-semibold text-stone-700 mb-1">Nama Bank</label>
                <input type="text" name="bank" value="{{ old('bank') ?? $user->bank }}"
                       class="w-full px-4 py-2.5 text-sm border border-stone-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-red-600">
            </div>
            <div>
                <label class="block text-xs font-semibold text-stone-700 mb-1">Nomor Rekening</label>
                <input type="text" name="no_rekening" value="{{ old('no_rekening') ?? $user->no_rekening }}"
                       class="w-full px-4 py-2.5 text-sm border border-stone-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-red-600">
            </div>
            <div>
                <label class="block text-xs font-semibold text-stone-700 mb-1">Atas Nama</label>
                <input type="text" name="atas_nama" value="{{ old('atas_nama') ?? $user->atas_nama }}"
                       class="w-full px-4 py-2.5 text-sm border border-stone-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-red-600">
            </div>
            <button type="submit" class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">Simpan</button>
        </form>
    </div>
</div>
@endsection
