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
