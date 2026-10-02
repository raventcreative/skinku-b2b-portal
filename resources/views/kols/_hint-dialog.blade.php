{{-- Isi penjelasan untuk tombol (?) (kols._hint). Satu catatan dipakai bergantian. --}}
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

{{-- Catatan ala comment Excel: menempel di samping tombol (?), tanpa menutupi halaman.
     Tutup: klik di luar, Esc, atau scroll. --}}
<div id="kolHintPop" role="tooltip" class="hidden fixed z-[1000] w-80 max-w-[90vw] rounded-lg border border-amber-300 bg-amber-50 shadow-lg p-3 text-[12px] text-stone-700 leading-relaxed normal-case font-normal text-left tracking-normal">
    <button type="button" onclick="kolHintClose()" class="absolute top-1 right-2 text-stone-400 hover:text-stone-700 text-sm" aria-label="Tutup">×</button>
    <div id="kolHintBody" class="pr-3"></div>
</div>
<script>
    var kolHintFor = null;
    function kolHintClose() { document.getElementById('kolHintPop').classList.add('hidden'); kolHintFor = null; }
    function kolHint(key, btn) {
        var t = document.getElementById('hint-' + key), pop = document.getElementById('kolHintPop');
        if (!t || !pop) return;
        if (kolHintFor === btn) { kolHintClose(); return; } // klik (?) yang sama = tutup
        document.getElementById('kolHintBody').innerHTML = t.innerHTML;
        pop.classList.remove('hidden');
        // Posisi: kanan tombol; kalau mentok kanan layar → kiri tombol. Jaga tetap di layar.
        var r = btn.getBoundingClientRect(), w = pop.offsetWidth, h = pop.offsetHeight, gap = 8;
        var left = r.right + gap;
        if (left + w > window.innerWidth - gap) left = Math.max(gap, r.left - w - gap);
        var top = Math.min(Math.max(gap, r.top - 8), window.innerHeight - h - gap);
        pop.style.left = left + 'px'; pop.style.top = Math.max(gap, top) + 'px';
        kolHintFor = btn;
    }
    document.addEventListener('click', function (e) {
        var pop = document.getElementById('kolHintPop');
        if (kolHintFor && pop && !pop.contains(e.target)) kolHintClose();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') kolHintClose(); });
    window.addEventListener('scroll', function () { if (kolHintFor) kolHintClose(); }, true);
</script>
