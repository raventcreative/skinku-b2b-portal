<?php

namespace App\Support;

/**
 * Helper teks kecil (zero-dependency).
 */
class Text
{
    /**
     * Teks bebas jadi HTML aman: di-escape dulu (anti XSS), URL http(s) di dalamnya
     * jadi tautan yang bisa diklik (buka tab baru), lalu baris baru jadi <br>.
     * Dipakai untuk deskripsi kartu Kanban, catatan pengumuman, dll.
     */
    public static function linkify(?string $text): string
    {
        $safe = e((string) $text);
        $safe = preg_replace(
            '~(https?://[^\s<]+)~i',
            '<a href="$1" target="_blank" rel="noopener" class="text-indigo-600 underline break-all">$1</a>',
            (string) $safe,
        );

        return nl2br((string) $safe);
    }
}
