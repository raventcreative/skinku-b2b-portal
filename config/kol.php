<?php

return [

    /*
     * TODO(Freddie): AMBANG CPM UNTUK VERDICT — INI PLACEHOLDER, BUKAN KEPUTUSAN.
     *
     * CPM (rupiah per 1000 views) ≤ ambang → 🟢 Worth It; di atasnya → 🔴 Kemahalan.
     * Excel sumber tidak menyebut angkanya eksplisit, jadi sengaja TIDAK ditebak.
     * 50.000 dipilih hanya supaya fiturnya hidup; ganti begitu Freddie menentukan.
     */
    'cpm_threshold' => (int) env('KOL_CPM_THRESHOLD', 50_000),

    /*
     * Pilihan kategori pada form KOL. Di config (bukan tabel) karena kols.kategori
     * berupa string bebas dan brief membatasi modul ini ke 3 tabel — daftar awal
     * dari Excel cukup jadi pilihan dropdown.
     */
    'kategori' => ['Skinfluencer', 'Makeup', 'Lifestyle', 'Lainnya'],

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
