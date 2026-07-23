<?php

namespace App\Services\Ai\Tools;

use App\Models\User;

/**
 * Satu kemampuan yang boleh dipanggil AI. Alat BACA jalan langsung; alat TULIS
 * (isWrite) tak pernah jalan tanpa konfirmasi user — `validate()` dipakai biar
 * AI "tanya balik" saat argumen belum jelas, `previewText()` jadi kalimat
 * konfirmasi, dan `run()` baru dipanggil SETELAH user setuju.
 */
interface AiTool
{
    public function name(): string;

    public function description(): string;

    /** JSON Schema objek parameter (buat provider). */
    public function parameters(): array;

    public function isWrite(): bool;

    /** Izin yang dibutuhkan (null = cukup akses halaman asisten). */
    public function permission(): ?string;

    /** Alat tulis: pesan minta-perjelas (AI tanya balik) atau null kalau sudah OK. */
    public function validate(array $args, User $user): ?string;

    /** Alat tulis: kalimat konfirmasi yang dibaca user. */
    public function previewText(array $args, User $user): string;

    /** Jalankan alat. Untuk tulis: dipanggil SETELAH konfirmasi. */
    public function run(array $args, User $user): array;
}
