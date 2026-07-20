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
];
