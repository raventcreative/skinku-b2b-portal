<?php

namespace App\Models;

use App\Models\Concerns\HasFiles;
use Illuminate\Database\Eloquent\Model;

/**
 * Pengumuman dashboard untuk satu role. Box catatan (teks) + popup banner
 * (gambar polimorfik lewat HasFiles/ImageService + link opsional).
 */
class Announcement extends Model
{
    use HasFiles;

    /** Koleksi gambar banner (satu per pengumuman). */
    public const BANNER = 'banner';

    protected $fillable = [
        'role', 'note_enabled', 'note_title', 'note_body', 'banner_enabled', 'banner_link',
    ];

    protected function casts(): array
    {
        return [
            'note_enabled' => 'boolean',
            'banner_enabled' => 'boolean',
        ];
    }

    public function bannerUrl(): ?string
    {
        return $this->firstFileUrl(self::BANNER);
    }

    /** Box catatan layak tampil: aktif & ada isinya. */
    public function noteVisible(): bool
    {
        return $this->note_enabled && filled($this->note_body);
    }

    /** Popup banner layak tampil: aktif & gambarnya ada. */
    public function bannerVisible(): bool
    {
        return $this->banner_enabled && $this->bannerUrl() !== null;
    }
}
