<?php

/*
| Portal Content Creator — batasan upload & platform (FRD §7).
| Angka mengikuti dokumentasi resmi per 2026-09; verifikasi ulang bila platform
| mengubah aturannya. Upload besar juga butuh upload_max_filesize/post_max_size
| PHP yang cukup di server.
*/
return [
    'image_max_kb' => (int) env('CONTENT_IMAGE_MAX_KB', 8192),
    'video_max_kb' => (int) env('CONTENT_VIDEO_MAX_KB', 307200),
    // Email kontak di halaman publik /privacy & /terms (wajib untuk review app TikTok/Meta).
    'legal_contact' => env('LEGAL_CONTACT_EMAIL', env('MAIL_FROM_ADDRESS')),

    'carousel_min' => 2,
    'carousel_max' => 10,

    // Retry publish gagal: jeda (menit) per percobaan; setelah habis → failed (FR-57).
    'retry_backoff_minutes' => [5, 15, 60],
    // Polling container video IG/Threads: 1x/menit, maksimal 30 kali (±30 menit).
    'container_max_polls' => 30,

    // mode: api = terbit otomatis; manual = admin posting & tempel link; auto = API bila akun
    // platform terhubung, selain itu manual (TikTok: Content Posting API, Fase 2).
    'platforms' => [
        'facebook' => ['label' => 'Facebook', 'mode' => 'api', 'caption_max' => 63206, 'types' => ['image', 'video', 'carousel']],
        'instagram' => ['label' => 'Instagram', 'mode' => 'api', 'caption_max' => 2200, 'hashtag_max' => 30, 'types' => ['image', 'video', 'carousel']],
        'threads' => ['label' => 'Threads', 'mode' => 'api', 'caption_max' => 500, 'types' => ['image', 'video', 'carousel']],
        'tiktok' => ['label' => 'TikTok', 'mode' => 'auto', 'caption_max' => 2200, 'types' => ['image', 'video', 'carousel']],
    ],
];
