@extends('layouts.app')
@section('title', 'Integrasi Shopee')
@section('heading', 'Integrasi Shopee')

@section('content')
<div class="bg-white rounded-2xl border border-stone-200 p-6 text-sm">
    <h3 class="text-base font-bold text-stone-800 mb-2">Koneksi Shopee</h3>
    @if(!$configured)
        <p class="text-rose-600">Kredensial Shopee belum diisi di <code>.env</code> server (SHOPEE_PARTNER_ID / SHOPEE_PARTNER_KEY).</p>
    @elseif($connection)
        <p class="text-emerald-700">Terhubung: <b>{{ $connection->shop_name ?? $connection->shop_id }}</b>.</p>
    @else
        <p class="text-stone-500">Belum terhubung ke toko Shopee.</p>
    @endif
</div>
@endsection
