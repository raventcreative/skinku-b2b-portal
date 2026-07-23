<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Memori Asisten AI — konteks bisnis per bagian terpandu. Diisi admin di halaman
 * "Pengetahuan AI", lalu `document()` merangkainya jadi blok teks yang
 * disuntikkan ke system-prompt (lihat AiAgentService). Satu baris per section.
 */
class AiKnowledge extends Model
{
    protected $table = 'ai_knowledge';

    protected $fillable = ['section', 'content'];

    /** Batas total karakter yang disuntik ke prompt (jaga token & biaya). */
    private const MAX_CHARS = 6000;

    /**
     * Bagian terpandu + pertanyaan pemandunya. Urutan = urutan tampil di form.
     * [key => [judul, pertanyaan-pemandu, placeholder-contoh]]
     */
    public const SECTIONS = [
        'business' => [
            'Tentang bisnis',
            'SKINKU jualan apa, model bisnisnya gimana (distributor B2B / reseller / afiliator?), siapa pelanggan utamanya, apa yang bikin beda?',
            'Contoh: SKINKU distributor B2B produk skincare lokal. Pelanggan: reseller & distributor daerah. Fokus kualitas + harga partai…',
        ],
        'products' => [
            'Produk & istilah penting',
            'Produk/kategori utama, dan singkatan/istilah internal yang sering dipakai.',
            'Contoh: Produk: Day Cream, Night Cream, Serum. Istilah: HPP=harga pokok, PO=order beli, KOL=influencer, HQ=pusat…',
        ],
        'team' => [
            'Tim & tanggung jawab',
            'Siapa anggota tim dan ngerjain apa. Ini bikin asisten tahu mau delegasi tugas (kartu Kanban) ke siapa.',
            'Contoh: Agatha = konten & video. Billy = community. Tiar = admin & stok. Gracelyn = desain…',
        ],
        'workflow' => [
            'Papan Kanban & alur kerja',
            'Papan yang aktif + kolomnya, dan gimana tugas mengalir.',
            'Contoh: Papan "Task SKINKU Management". Kolom: To Do → Proses → Selesai per orang. Tugas baru taruh di To Do…',
        ],
        'priorities' => [
            'Fokus & target sekarang',
            'Prioritas/target bulan atau kuartal ini yang perlu asisten tahu.',
            'Contoh: Q3 2026 fokus rekrut 50 reseller baru + naikin konten TikTok 3x/minggu…',
        ],
        'rules' => [
            'Aturan & gaya bicara',
            'Hal yang asisten HARUS patuhi atau hindari saat menjawab/bertindak.',
            'Contoh: Jangan pernah janjikan diskon tanpa approval. Pakai bahasa santai. Kalau ragu, tanya dulu…',
        ],
        'notes' => [
            'Catatan bebas',
            'Apa pun lain yang perlu asisten tahu tentang bisnismu.',
            'Contoh: Libur gudang tiap Minggu. Supplier utama di Surabaya…',
        ],
    ];

    /** Isi tersimpan per section: [key => content]. */
    public static function map(): array
    {
        return static::query()->pluck('content', 'section')->all();
    }

    /** Ada isi yang layak disuntik? */
    public static function hasAny(): bool
    {
        return static::query()->whereNotNull('content')->where('content', '!=', '')->exists();
    }

    /**
     * Rangkai bagian yang terisi jadi satu blok teks buat system-prompt.
     * Kosong → '' (asisten jalan tanpa konteks tambahan). Dipotong di MAX_CHARS.
     */
    public static function document(): string
    {
        $map = static::map();
        $parts = [];
        foreach (self::SECTIONS as $key => [$title]) {
            $content = trim((string) ($map[$key] ?? ''));
            if ($content !== '') {
                $parts[] = "## {$title}\n{$content}";
            }
        }

        if ($parts === []) {
            return '';
        }

        return Str::limit(implode("\n\n", $parts), self::MAX_CHARS, ' …(dipotong)');
    }
}
