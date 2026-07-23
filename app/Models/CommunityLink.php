<?php

namespace App\Models;

use App\Models\Concerns\HasFiles;
use Illuminate\Database\Eloquent\Model;

/**
 * Link Komunitas WA untuk satu role. Ditampilkan sebagai tombol
 * "Gabung Komunitas WA" di sidebar: klik -> buka link, atau popup QR bila
 * gambar QR diunggah. QR opsional lewat HasFiles/ImageService (polimorfik).
 * Diatur di halaman Pengumuman (izin manage_announcements), satu per role.
 */
class CommunityLink extends Model
{
    use HasFiles;

    /** Koleksi gambar QR (satu per komunitas). */
    public const QR = 'qr';

    protected $fillable = ['role', 'enabled', 'label', 'link'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /** Teks tombol (default bila kosong). */
    public function buttonLabel(): string
    {
        return $this->label ?: 'Gabung Komunitas WA';
    }

    public function qrUrl(): ?string
    {
        return $this->firstFileUrl(self::QR);
    }

    /** Layak tampil di sidebar: aktif & link terisi. */
    public function visible(): bool
    {
        return $this->enabled && filled($this->link);
    }
}
