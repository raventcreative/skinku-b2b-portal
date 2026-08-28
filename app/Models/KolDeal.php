<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class KolDeal extends Model
{
    use SoftDeletes;

    public const JENIS = ['vt', 'live'];

    /** Tipe komersial deal (port Iyuro) — beda sumbu dari JENIS (format konten). */
    public const DEAL_TYPES = ['paid', 'barter', 'affiliate_only'];

    public const DEAL_TYPE_LABEL = [
        'paid' => 'Paid promote',
        'barter' => 'Barter produk',
        'affiliate_only' => 'Affiliate (komisi saja)',
    ];

    public const STATUSES = ['draft', 'berjalan', 'selesai', 'batal'];

    public const STATUS_BAYAR = ['belum', 'dp', 'lunas'];

    /** Tujuan endorse — penentu dasar verdict laporan hasil. */
    public const TUJUAN = ['penjualan', 'awareness'];

    public const VERDICT_BAGUS = '🟢 Bagus';

    public const VERDICT_CUKUP = '🟡 Cukup';

    public const VERDICT_JELEK = '🔴 Jelek';

    public const VERDICT_BELUM = '— belum';

    /**
     * Field yang hanya boleh dilihat/diisi pemegang kol.deal.finance. Satu
     * daftar dipakai controller (buang input) DAN test (pastikan tak bocor) —
     * dua salinan pasti pelan-pelan beda.
     */
    public const FINANCE_FIELDS = ['total_biaya', 'other_cost', 'dp_percent', 'status_bayar', 'no_rekening', 'bank', 'atas_nama', 'payment_note'];

    protected $fillable = [
        'kode', 'kol_id', 'kol_campaign_id', 'jenis', 'deal_type', 'ratecard_deal', 'jumlah_slot',
        'periode_mulai', 'periode_selesai', 'pic_user_id', 'link_mou', 'status',
        'deliverables', 'posting_deadline', 'usage_rights', 'internal_notes',
        'total_biaya', 'other_cost', 'status_bayar', 'dp_percent', 'no_rekening', 'bank', 'atas_nama', 'payment_note',
        'hasil_tujuan', 'hasil_video_upload', 'hasil_video_fyp', 'hasil_views',
        'hasil_revenue', 'hasil_catatan', 'hasil_diisi_at',
    ];

    protected function casts(): array
    {
        return [
            'periode_mulai' => 'date',
            'periode_selesai' => 'date',
            'posting_deadline' => 'date',
            'ratecard_deal' => 'integer',
            'total_biaya' => 'integer',
            'other_cost' => 'integer',
            'dp_percent' => 'integer',
            'jumlah_slot' => 'integer',
            'hasil_video_upload' => 'integer',
            'hasil_video_fyp' => 'integer',
            'hasil_views' => 'integer',
            'hasil_revenue' => 'integer',
            'hasil_diisi_at' => 'datetime',
        ];
    }

    /** Grand total biaya = fee (total_biaya) + biaya lain + subtotal HPP sampel. */
    public function grandTotal(): int
    {
        return (int) $this->total_biaya + (int) $this->other_cost
            + (int) $this->samples->sum(fn ($s) => $s->subtotal);
    }

    /** Nominal DP (Rp) bila status bayar = dp & persen terisi. */
    public function dpAmount(): int
    {
        return $this->status_bayar === 'dp' && $this->dp_percent > 0
            ? (int) round($this->total_biaya * $this->dp_percent / 100)
            : 0;
    }

    /** Pembayaran lewat tenggat: masih belum lunas & periode_selesai sudah lewat. */
    public function isPaymentOverdue(): bool
    {
        return $this->status_bayar !== 'lunas'
            && $this->total_biaya > 0
            && $this->periode_selesai !== null
            && $this->periode_selesai->isPast();
    }

    public function dealTypeLabel(): string
    {
        return self::DEAL_TYPE_LABEL[$this->deal_type] ?? $this->deal_type;
    }

    /** Laporan hasil sudah pernah diisi? */
    public function getHasilTerisiAttribute(): bool
    {
        return $this->hasil_diisi_at !== null || $this->hasil_views !== null || $this->hasil_revenue !== null;
    }

    /** CPM aktual = biaya / views * 1000. Butuh biaya & views > 0. */
    public function getHasilCpmAttribute(): ?int
    {
        return ($this->total_biaya > 0 && $this->hasil_views > 0)
            ? (int) round($this->total_biaya / $this->hasil_views * 1000)
            : null;
    }

    /** ROMI = revenue / biaya (kelipatan balik modal). Butuh biaya > 0 & revenue terisi. */
    public function getHasilRomiAttribute(): ?float
    {
        return ($this->total_biaya > 0 && $this->hasil_revenue !== null)
            ? round($this->hasil_revenue / $this->total_biaya, 2)
            : null;
    }

    public function getHasilAvgViewsAttribute(): ?int
    {
        return $this->hasil_video_upload > 0
            ? (int) round($this->hasil_views / $this->hasil_video_upload)
            : null;
    }

    /**
     * Verdict menyesuaikan TUJUAN endorse:
     * - penjualan -> ROMI (>=2 Bagus, >=1 Cukup, <1 Jelek).
     * - awareness -> CPM (patokan sama kurasi KOL: <60rb Bagus, <120rb Cukup, else Jelek).
     * Tanpa revenue tetap dapat nilai lewat CPM (awareness).
     */
    public function getHasilVerdictAttribute(): string
    {
        if ($this->hasil_tujuan === 'penjualan') {
            $romi = $this->hasil_romi;
            if ($romi === null) {
                return self::VERDICT_BELUM;
            }

            return match (true) {
                $romi >= 2 => self::VERDICT_BAGUS,
                $romi >= 1 => self::VERDICT_CUKUP,
                default => self::VERDICT_JELEK,
            };
        }

        if ($this->hasil_tujuan === 'awareness') {
            $cpm = $this->hasil_cpm;
            if ($cpm === null) {
                return self::VERDICT_BELUM;
            }

            return match (true) {
                $cpm < config('kol.median_worth') => self::VERDICT_BAGUS,
                $cpm < config('kol.median_masih_oke') => self::VERDICT_CUKUP,
                default => self::VERDICT_JELEK,
            };
        }

        return self::VERDICT_BELUM;
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }

    public function campaign()
    {
        return $this->belongsTo(KolCampaign::class, 'kol_campaign_id');
    }

    /** Konten yang terkait deal ini — dipakai reminder "deal belum posting". */
    public function contents()
    {
        return $this->hasMany(KolContent::class, 'kol_deal_id');
    }

    /** Sampel produk yang dikirim untuk deal ini. */
    public function samples()
    {
        return $this->hasMany(KolSample::class, 'kol_deal_id')->latest('id');
    }

    public function pic()
    {
        return $this->belongsTo(User::class, 'pic_user_id');
    }

    /** Pola nomor PO existing: SKN-KOL-YYYYMMDD-XXXX, dijamin unik termasuk yang terhapus. */
    public static function generateKode(): string
    {
        $date = now()->format('Ymd');
        do {
            $candidate = sprintf('SKN-KOL-%s-%04d', $date, random_int(1, 9999));
        } while (self::withTrashed()->where('kode', $candidate)->exists());

        return $candidate;
    }
}
