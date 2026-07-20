<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class KolDeal extends Model
{
    use SoftDeletes;

    public const JENIS = ['vt', 'live'];

    public const STATUSES = ['draft', 'berjalan', 'selesai', 'batal'];

    public const STATUS_BAYAR = ['belum', 'dp', 'lunas'];

    /**
     * Field yang hanya boleh dilihat/diisi pemegang kol.deal.finance. Satu
     * daftar dipakai controller (buang input) DAN test (pastikan tak bocor) —
     * dua salinan pasti pelan-pelan beda.
     */
    public const FINANCE_FIELDS = ['total_biaya', 'status_bayar', 'no_rekening', 'bank', 'atas_nama'];

    protected $fillable = [
        'kode', 'kol_id', 'jenis', 'ratecard_deal', 'jumlah_slot',
        'periode_mulai', 'periode_selesai', 'pic_user_id', 'link_mou', 'status',
        'total_biaya', 'status_bayar', 'no_rekening', 'bank', 'atas_nama',
    ];

    protected function casts(): array
    {
        return [
            'periode_mulai' => 'date',
            'periode_selesai' => 'date',
            'ratecard_deal' => 'integer',
            'total_biaya' => 'integer',
            'jumlah_slot' => 'integer',
        ];
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
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
