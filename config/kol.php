<?php

return [

    /*
     * Tingkatan verdict — DARI RUMUS ASLI file KOL SKINKU.xlsx (bukan tebakan;
     * placeholder lama sudah diganti). Sheet memakai dua skala:
     *
     * Kolom V (indikator MEDIAN, 3 tingkat):
     *   CPM < 60rb → 🟢 Worth It · < 120rb → 🟡 Masih Oke · sisanya 🔴 Kemahalan
     *
     * Kolom U (indikator MEAN, 5 tingkat — rumus terbaru; baris-baris awal sheet
     * masih memakai rumus lama 3 tingkat, versi 5 tingkat inilah yang dipakai):
     *   CPM < 10rb Sangat Bagus · < 20rb Bagus · < 30rb Dipertimbangkan ·
     *   < 50rb Buruk · sisanya Sangat Buruk
     */
    'median_worth' => 60_000,
    'median_masih_oke' => 120_000,
    'mean_tiers' => [
        [10_000, '🟢 Sangat Bagus'],
        [20_000, '🟡 Bagus'],
        [30_000, '🟠 Dipertimbangkan'],
        [50_000, '🔴 Buruk'],
    ],
    'mean_tier_terburuk' => '⚫ Sangat Buruk',

    /*
     * Pilihan kategori pada form KOL. Di config (bukan tabel) karena kols.kategori
     * berupa string bebas dan brief membatasi modul ini ke 3 tabel — daftar awal
     * dari Excel cukup jadi pilihan dropdown.
     */
    'kategori' => ['Skinfluencer', 'Makeup', 'Lifestyle', 'Lainnya'],

    /*
     * Platform sosial media + templat URL profil (%s = handle tanpa @). Dipakai
     * agar klik username langsung membuka profilnya. 'lainnya' tanpa templat —
     * untuk platform lain, andalkan Link profil manual. Urutan = urutan dropdown.
     */
    'platforms' => [
        'tiktok' => ['label' => 'TikTok', 'url' => 'https://www.tiktok.com/@%s'],
        'instagram' => ['label' => 'Instagram', 'url' => 'https://www.instagram.com/%s'],
        'youtube' => ['label' => 'YouTube', 'url' => 'https://www.youtube.com/@%s'],
        'shopee' => ['label' => 'Shopee', 'url' => 'https://shopee.co.id/%s'],
        'lainnya' => ['label' => 'Lainnya', 'url' => null],
    ],

    /*
     * GMV + Viral + Fake Detector — angka-angka ini DARI RUMUS EXCEL kolom W
     * (terbaca langsung di formula bar), bukan tebakan:
     *   =IF(F5="","","GMV "&TEXT(ROUND(R5*0.012*38000,0),...)
     *     &" Viral:"&IF(Q5>=R5*2,"High",IF(Q5>=R5*1.3,"Mid","Low"))
     *     &" Fake:"&IF(R5<F5*0.02,"Red",IF(R5<F5*0.05,"Watch","Safe")))
     *   F=followers, Q=mean views, R=median views.
     * Divalidasi dengan baris nyata: median 6.627 → GMV 3.021.912 (cocok sel Excel).
     */
    'gmv_conversion' => (float) env('KOL_GMV_CONVERSION', 0.012),   // 1,2% penonton jadi pembeli
    'gmv_avg_order' => (int) env('KOL_GMV_AVG_ORDER', 38_000),      // nilai order rata-rata (Rp)
    'viral_high' => 2.0,     // mean ≥ median×2   → High (viewsnya meledak, bukan stabil)
    'viral_mid' => 1.3,      // mean ≥ median×1.3 → Mid
    'fake_red' => 0.02,      // median < 2% followers  → Red (indikasi followers palsu)
    'fake_watch' => 0.05,    // median < 5% followers  → Watch
];
