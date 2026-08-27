@extends('layouts.app')
@section('title', 'Import Data Affiliate')
@section('heading', 'Import Data Affiliate')

@section('content')
<a href="{{ route('kol-affiliate.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Affiliate &amp; GMV</a>

<div class="max-w-2xl mt-3">
    @if($errors->any())
        <div class="mb-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="bg-white rounded-2xl border border-stone-200 p-5 space-y-4">
        <p class="text-sm text-stone-500">
            Upload export <b>XLSX/CSV</b> dari <b>TikTok Affiliate Center / Shopee</b> (atau export app lokal).
            Kolom dikenali otomatis: <span class="font-mono text-xs">username, order id, gmv, komisi, qty, produk, status, tanggal</span>.
            Order dengan <b>Order ID sama</b> otomatis di-replace (aman re-upload).
        </p>

        <form method="POST" action="{{ route('kol-affiliate.import.store') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <label class="block text-sm">
                <span class="text-xs font-semibold text-stone-600">Platform</span>
                <select name="platform" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg bg-white">
                    <option value="tiktok">TikTok Affiliate</option>
                    <option value="shopee">Shopee Affiliate</option>
                </select>
            </label>
            <label class="block text-sm">
                <span class="text-xs font-semibold text-stone-600">File export (.xlsx / .csv)</span>
                <input type="file" name="file" accept=".xlsx,.csv,.txt" required class="mt-1 w-full text-sm border border-stone-300 rounded-lg p-2 bg-white">
            </label>
            <div class="flex items-center gap-4">
                <button class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">Import sekarang</button>
                <span class="text-[11px] text-stone-400">Username yang tak dikenal masuk daftar "Belum Cocok" untuk ditautkan manual.</span>
            </div>
        </form>
    </div>
</div>
@endsection
