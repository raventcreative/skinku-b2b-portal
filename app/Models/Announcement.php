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
        'role', 'sort_order', 'note_enabled', 'note_title', 'note_body', 'note_link', 'note_link_label',
        'banner_enabled', 'banner_link',
    ];

    protected function casts(): array
    {
        return [
            'note_enabled' => 'boolean',
            'banner_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Label ringkas untuk daftar pengelolaan. */
    public function label(): string
    {
        return $this->note_title
            ?: ($this->note_enabled ? 'Catatan tanpa judul' : ($this->banner_enabled ? 'Banner' : 'Pengumuman kosong'));
    }

    public function bannerUrl(): ?string
    {
        return $this->firstFileUrl(self::BANNER);
    }

    /** Box catatan layak tampil: aktif & ada isi/tautan. */
    public function noteVisible(): bool
    {
        return $this->note_enabled && (filled($this->note_body) || filled($this->note_link));
    }

    /** Label tombol link catatan (default "Klik di sini"). */
    public function noteLinkLabel(): string
    {
        return $this->note_link_label ?: 'Klik di sini';
    }

    /**
     * Isi catatan siap tampil: di-escape dulu (aman XSS), URL http(s) di dalamnya
     * jadi tautan yang bisa diklik, baris baru jadi <br>.
     */
    public function noteBodyHtml(): string
    {
        $safe = e((string) $this->note_body);
        $safe = preg_replace(
            '~(https?://[^\s<]+)~',
            '<a href="$1" target="_blank" rel="noopener" class="underline font-medium break-all">$1</a>',
            (string) $safe,
        );

        return nl2br((string) $safe);
    }

    /** Popup banner layak tampil: aktif & gambarnya ada. */
    public function bannerVisible(): bool
    {
        return $this->banner_enabled && $this->bannerUrl() !== null;
    }
}
