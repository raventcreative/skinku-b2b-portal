@extends('layouts.app')
@section('title', 'Ubah Password')
@section('heading', 'Ubah Password')

@section('content')
<div class="max-w-md">
    <div class="bg-white rounded-2xl border border-stone-200 p-6">
        <h3 class="text-sm font-bold text-stone-900 mb-4">Ganti Password Akun Anda</h3>
        <form method="POST" action="{{ route('account.password') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-xs font-semibold text-stone-700 mb-1">Password Saat Ini</label>
                <input type="password" name="current_password" required
                       class="w-full px-4 py-2.5 text-sm border border-stone-300 rounded-xl focus:outline-hidden focus:ring-2 focus:ring-red-600">
            </div>
            <div>
                <label class="block text-xs font-semibold text-stone-700 mb-1">Password Baru</label>
                <input type="password" name="password" required
                       class="w-full px-4 py-2.5 text-sm border border-stone-300 rounded-xl focus:outline-hidden focus:ring-2 focus:ring-red-600">
            </div>
            <div>
                <label class="block text-xs font-semibold text-stone-700 mb-1">Konfirmasi Password Baru</label>
                <input type="password" name="password_confirmation" required
                       class="w-full px-4 py-2.5 text-sm border border-stone-300 rounded-xl focus:outline-hidden focus:ring-2 focus:ring-red-600">
            </div>
            <button type="submit" class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">Simpan</button>
        </form>
    </div>
</div>
@endsection
