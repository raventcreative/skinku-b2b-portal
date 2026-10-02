{{-- Isi penjelasan untuk tombol (?) (kols._hint). Satu dialog dipakai bergantian. --}}
<template id="hint-aps">
    <p class="font-bold text-stone-800 mb-1">APS — Affiliate Potential Score (0–100)</p>
    <p class="mb-2">Menilai <b>affiliate yang SUDAH jualan SKINKU</b>: layak dibina atau cukup dipantau. Dihitung dari 4 minggu terakhir.</p>
    <ul class="list-disc pl-4 space-y-0.5 mb-2">
        <li><b>35%</b> Pertumbuhan GMV SKINKU minggu ke minggu</li>
        <li><b>25%</b> Efisiensi konversi (RPM = Rupiah per 1.000 views)</li>
        <li><b>20%</b> Konsistensi posting tiap minggu</li>
        <li><b>20%</b> Skala GMV</li>
    </ul>
    <p>≥75 <b class="text-emerald-700">Bina intensif</b> · ≥50 <b class="text-amber-600">Pantau &amp; dorong</b> · &lt;50 <b class="text-rose-600">Nurture</b>. Butuh minimal 4 minggu data (sebelumnya "new"); 2 minggu tak posting → skor maks 40.</p>
</template>
<template id="hint-kss">
    <p class="font-bold text-stone-800 mb-1">KSS — KOL Selection Score (0–100)</p>
    <p class="mb-2">Menilai <b>calon KOL SEBELUM deal</b>: layak dibayar sesuai ratecard-nya atau tidak.</p>
    <ul class="list-disc pl-4 space-y-0.5 mb-2">
        <li><b>35%</b> Efisiensi biaya (eCPM = ratecard ÷ median views × 1.000)</li>
        <li><b>20%</b> Engagement rate</li>
        <li><b>20%</b> Relevansi niche skincare/beauty</li>
        <li><b>15%</b> Riwayat kerja sama dengan brand</li>
        <li><b>10%</b> Kesiapan komersial (rutin keranjang kuning)</li>
    </ul>
    <p>≥70 <b class="text-emerald-700">Shortlist</b> · ≥50 <b class="text-amber-600">Nego dulu</b> · &lt;50 <b class="text-rose-600">Tolak sopan, simpan</b>. Klik "hitung" di kolom KSS → isian terisi otomatis dari data yang ada.</p>
</template>
<template id="hint-gpm">
    <p class="font-bold text-stone-800 mb-1">GPM — GMV per 1.000 views</p>
    <p>Rupiah penjualan yang dihasilkan tiap 1.000 penonton video jualan (30 hari, semua brand, dari TikTok). <b>Makin tinggi = makin jago menjual</b>, bukan cuma ramai views. Contoh: GPM Rp 50.000 → 100 rb views ≈ Rp 5 jt GMV.</p>
</template>
<template id="hint-porsi">
    <p class="font-bold text-stone-800 mb-1">Porsi SKINKU</p>
    <p class="mb-2">GMV SKINKU 30 hari ÷ GMV Asli 30 hari (semua brand). Berapa persen jualan kreator ini yang masuk ke SKINKU.</p>
    <p>≥20% (hijau) = sudah loyal. Kecil tapi GMV Asli besar = jago jualan brand lain → peluang push SKINKU. <b>100%+ ⚠</b> = data GMV Asli TikTok sudah lama → Perbarui performa di detail KOL.</p>
</template>

<dialog id="kolHintDlg" class="rounded-2xl p-0 w-[min(92vw,26rem)] backdrop:bg-black/30" onclick="if (event.target === this) this.close()">
    <div class="p-5 text-[13px] text-stone-600 leading-relaxed">
        <div id="kolHintBody"></div>
        <button type="button" onclick="document.getElementById('kolHintDlg').close()" class="mt-4 w-full px-4 py-2 rounded-lg bg-stone-800 text-white text-xs font-semibold">Mengerti</button>
    </div>
</dialog>
<script>
    function kolHint(key) {
        var t = document.getElementById('hint-' + key), d = document.getElementById('kolHintDlg');
        if (!t || !d) return;
        document.getElementById('kolHintBody').innerHTML = t.innerHTML;
        d.showModal();
    }
</script>
