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

    protected $fillable = ['section', 'group', 'content'];

    /** Batas total karakter yang disuntik ke prompt (jaga token & biaya). */
    private const MAX_CHARS = 6000;

    /** Grup pengetahuan → label tab (urutan = urutan tab). */
    public const GROUPS = ['sistem' => 'Sistem', 'chat' => 'Chat E-commerce'];

    /** section key => grup. Key yang tak tercantum dianggap 'sistem'. */
    private const SECTION_GROUP = [
        'chat_products' => 'chat',
        'chat_policy' => 'chat',
        'chat_faq' => 'chat',
        'chat_tone' => 'chat',
    ];

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
        'okr_strategy' => [
            'Strategi & aturan OKR',
            'Arah tahunan, angka baseline, batas kapasitas tim, dan aturan pembagian target yang perlu dipakai AI saat menyusun OKR.',
            'Contoh: Target tahunan omzet Rp10 M. Konten maksimal 5 video/minggu. Billy pegang community; keputusan diskon tetap perlu approval Freddie…',
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
        'chat_products' => [
            'Produk (untuk customer)',
            'Info produk yang boleh dibagikan ke pembeli: nama, varian, ukuran, kegunaan, harga jual, stok/pre-order.',
            'Contoh: Day Cream 30ml Rp89.000 — brightening, aman ibu hamil. Serum 20ml Rp120.000. Ready stock kecuali varian Acne (PO 3 hari)…',
        ],
        'chat_policy' => [
            'Kebijakan kirim/retur/batal',
            'Aturan yang AI boleh sampaikan sendiri: jam & estimasi kirim, kurir, syarat retur/refund, cara & batas waktu pembatalan.',
            'Contoh: Kirim H+1 (order sebelum jam 3 sore). Kurir JNE/J&T. Retur 3 hari bila rusak/salah kirim, wajib video unboxing. Batal hanya sebelum dikirim…',
        ],
        'chat_faq' => [
            'FAQ',
            'Pertanyaan yang sering ditanya pembeli + jawaban bakunya.',
            'Contoh: "BPOM?" → semua produk terdaftar BPOM. "COD?" → belum ada COD, transfer/e-wallet. "Bisa grosir?" → min 12 pcs harga reseller…',
        ],
        'chat_tone' => [
            'Gaya bahasa ke pembeli',
            'Nada & aturan bicara saat membalas pembeli (beda dari gaya internal).',
            'Contoh: Ramah, singkat, pakai "Kak". Selalu ucap terima kasih. Jangan janji diskon tanpa promo resmi. Kalau ragu, arahkan ke admin…',
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

    /** Section untuk satu grup (keyed by section key). */
    public static function sectionsOf(string $group): array
    {
        return array_filter(
            self::SECTIONS,
            fn ($key) => self::groupOf($key) === $group,
            ARRAY_FILTER_USE_KEY
        );
    }

    /** Grup sebuah section ('sistem' bila tak terdaftar). */
    public static function groupOf(string $key): string
    {
        return self::SECTION_GROUP[$key] ?? 'sistem';
    }

    /**
     * Rangkai bagian terisi grup ini jadi satu blok teks buat system-prompt.
     * Default 'sistem' → perilaku lama (asisten internal & OKR tak berubah).
     * Kosong → '' . Dipotong di MAX_CHARS.
     */
    public static function document(string $group = 'sistem'): string
    {
        $map = static::map();
        $parts = [];
        foreach (self::sectionsOf($group) as $key => [$title]) {
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
