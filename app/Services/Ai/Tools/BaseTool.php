<?php

namespace App\Services\Ai\Tools;

use App\Models\User;

/**
 * Default aman untuk alat: BACA, tanpa izin khusus, tanpa validasi/konfirmasi.
 * Alat baca cukup override name/description/parameters/run. Alat tulis override
 * isWrite()=true + validate()/previewText().
 */
abstract class BaseTool implements AiTool
{
    /** Catatan utk AI saat HPP/biaya disembunyikan — supaya tak dikira nol/kosong. */
    protected const CATATAN_HPP = 'HPP, harga beli & biaya produksi tidak ditampilkan sesuai hak akses user (khusus izin "Lihat HPP"; default hanya super admin).';

    public function isWrite(): bool
    {
        return false;
    }

    public function permission(): ?string
    {
        return null;
    }

    public function availableFor(User $user): bool
    {
        return true;
    }

    /** HPP, harga beli & biaya produksi (modal) hanya utk izin view_hpp — default super admin; admin/gudang tidak. */
    protected function bolehLihatHpp(User $user): bool
    {
        return $user->canDo('view_hpp');
    }

    /** Tanggal YYYY-MM-DD dari AI → string, atau null bila kosong/ngawur. */
    protected function tanggal(?string $v): ?string
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }

    public function validate(array $args, User $user): ?string
    {
        return null;
    }

    public function previewText(array $args, User $user): string
    {
        return '';
    }
}
