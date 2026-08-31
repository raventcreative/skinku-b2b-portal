@extends('layouts.app')
@section('title', 'Import Data Affiliate')
@section('heading', 'Import Data Affiliate')

@section('content')
<a href="{{ route('kol-affiliate.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Affiliate &amp; GMV</a>

<div class="max-w-2xl mt-3 space-y-4">
    {{-- Panduan cara ambil file (collapsible) — hilangkan bingung "file yang mana". --}}
    <details class="bg-stone-50 rounded-2xl border border-stone-200 overflow-hidden group">
        <summary class="cursor-pointer select-none px-5 py-3.5 text-sm font-semibold text-stone-700 flex items-center gap-2 hover:bg-stone-100">
            <span>📄 Cara ambil file export-nya</span>
            <span class="ml-auto text-xs text-stone-400 group-open:hidden">buka ▾</span>
            <span class="ml-auto text-xs text-stone-400 hidden group-open:inline">tutup ▴</span>
        </summary>
        <div class="px-5 pb-5 pt-1 text-sm text-stone-600 space-y-3 border-t border-stone-200">
            <div>
                <p class="font-semibold text-stone-700 mb-1">TikTok / Tokopedia Affiliate Center</p>
                <ol class="list-decimal pl-5 space-y-0.5 text-[13px]">
                    <li>Buka <span class="font-mono text-xs">affiliate-id.tokopedia.com</span> (login akun seller).</li>
                    <li>Masuk laporan <b>pesanan / analitik creator</b> (mis. menu <b>Analitik</b> atau <b>Pembayaran</b>).</li>
                    <li>Klik <b>Export / Unduh</b> → pilih rentang tanggal → format <b>.xlsx</b> atau <b>.csv</b>.</li>
                </ol>
            </div>
            <div>
                <p class="font-semibold text-stone-700 mb-1">Shopee Affiliate</p>
                <ol class="list-decimal pl-5 space-y-0.5 text-[13px]">
                    <li>Buka dashboard Shopee Affiliate → laporan <b>konversi / order</b>.</li>
                    <li>Klik <b>Export</b> → simpan .xlsx / .csv.</li>
                </ol>
            </div>
            <p class="text-[12px] text-stone-500 bg-white rounded-lg border border-stone-200 px-3 py-2">
                💡 Yang penting file punya kolom: <b>username creator</b>, <b>order id</b>, <b>GMV</b> (komisi &amp; tanggal opsional).
                Nama kolom apa pun tak masalah — nanti bisa dipetakan di wizard. Tampilan menu bisa beda sedikit karena marketplace sering update; intinya cari tombol <b>Export/Unduh</b>.
            </p>
        </div>
    </details>

    <div class="bg-white rounded-2xl border border-stone-200 p-5 space-y-4">
        <p class="text-sm text-stone-500">
            Order dengan <b>Order ID sama</b> otomatis di-replace — <b>aman re-upload</b> kapan pun.
            Username tak dikenal masuk daftar "Belum Cocok".
        </p>

        <form method="POST" action="{{ route('kol-affiliate.import.store') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <label class="block text-sm">
                <span class="text-xs font-semibold text-stone-600">Platform</span>
                <select id="impPlatform" name="platform" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg bg-white">
                    <option value="tiktok">TikTok Affiliate</option>
                    <option value="shopee">Shopee Affiliate</option>
                </select>
            </label>

            {{-- Drop zone --}}
            <div>
                <span class="text-xs font-semibold text-stone-600">File export (.xlsx / .csv)</span>
                <label id="dropZone" for="impFile"
                    class="mt-1 flex flex-col items-center justify-center gap-1 px-4 py-8 border-2 border-dashed border-stone-300 rounded-xl bg-stone-50 cursor-pointer text-center transition hover:border-red-400 hover:bg-red-50/40">
                    <span class="text-2xl">⬆️</span>
                    <span id="dropText" class="text-sm text-stone-500"><b class="text-stone-700">Seret file ke sini</b> atau klik untuk pilih</span>
                    <span class="text-[11px] text-stone-400">.xlsx · .csv · maks 10MB</span>
                </label>
                <input type="file" id="impFile" name="file" accept=".xlsx,.csv,.txt" required class="hidden">
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button formaction="{{ route('kol-affiliate.import.preview') }}"
                    class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">Preview &amp; petakan kolom →</button>
                <button class="px-5 py-2.5 bg-white border border-stone-300 hover:bg-stone-50 text-stone-700 text-sm font-semibold rounded-xl">Import langsung (auto)</button>
            </div>
            <p class="text-[11px] text-stone-400">
                <b>Preview</b> = wizard pemetaan kolom + cek 20 baris dulu (disarankan untuk file baru).
                <b>Import langsung</b> = pakai deteksi kolom otomatis (untuk format yang sudah dikenal).
            </p>
        </form>
    </div>
</div>

<script>
(function () {
    var input = document.getElementById('impFile');
    var zone = document.getElementById('dropZone');
    var text = document.getElementById('dropText');
    var platform = document.getElementById('impPlatform');
    if (!input || !zone) return;

    // Ingat platform terakhir.
    try {
        var last = localStorage.getItem('skinku-aff-import-platform');
        if (last) platform.value = last;
    } catch (e) {}
    platform.addEventListener('change', function () {
        try { localStorage.setItem('skinku-aff-import-platform', platform.value); } catch (e) {}
    });

    function showName() {
        if (input.files && input.files.length) {
            text.innerHTML = '<b class="text-stone-800">' + input.files[0].name + '</b> — siap diimport';
            zone.classList.add('border-red-400', 'bg-red-50/40');
        }
    }
    input.addEventListener('change', showName);

    ['dragenter', 'dragover'].forEach(function (ev) {
        zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('border-red-500', 'bg-red-50'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('border-red-500', 'bg-red-50'); });
    });
    zone.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files.length) { input.files = e.dataTransfer.files; showName(); }
    });
})();
</script>
@endsection
