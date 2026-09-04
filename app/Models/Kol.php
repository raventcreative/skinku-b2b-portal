<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Kol extends Model
{
    use SoftDeletes;

    public const STATUS_PROSPEK = 'prospek';

    public const STATUS_AKTIF = 'aktif';

    public const STATUS_HOLD = 'hold';

    public const STATUS_NON_AKTIF = 'non_aktif';

    public const STATUS_BLACKLIST = 'blacklist';

    public const STATUSES = [self::STATUS_PROSPEK, self::STATUS_AKTIF, self::STATUS_HOLD, self::STATUS_NON_AKTIF, self::STATUS_BLACKLIST];

    public const ROLES = ['kol', 'affiliate', 'both'];

    public const ROLE_LABELS = ['kol' => 'KOL', 'affiliate' => 'Affiliate', 'both' => 'KOL + Affiliate'];

    protected $fillable = [
        'tiktok_username', 'name', 'role', 'is_gapok', 'platform', 'tiktok_link', 'followers', 'tiktok_checked_at', 'kategori', 'provinsi',
        'agency', 'manager_name', 'manager_contact', 'phone', 'status', 'blacklist_reason',
        'barter_ok', 'tiktok_shop_active', 'shopee_affiliate_active', 'voucher_code', 'tracking_link', 'usage_rights', 'catatan',
    ];

    protected $attributes = ['role' => 'kol'];

    protected function casts(): array
    {
        return [
            'followers' => 'integer',
            'tiktok_checked_at' => 'datetime',
            'is_gapok' => 'boolean',
            'barter_ok' => 'boolean',
            'tiktok_shop_active' => 'boolean',
            'shopee_affiliate_active' => 'boolean',
        ];
    }

    /** Nama tampilan (bila diisi) atau username sebagai fallback. */
    public function getDisplayNameAttribute(): string
    {
        return filled($this->name) ? $this->name : $this->tiktok_username;
    }

    public function isBlacklisted(): bool
    {
        return $this->status === self::STATUS_BLACKLIST;
    }

    /** Handle bersih tanpa '@' — dasar merakit URL profil. */
    public function handle(): string
    {
        return ltrim((string) $this->tiktok_username, '@');
    }

    /** Nama platform yang enak dibaca (TikTok, Instagram, …). */
    public function platformLabel(): string
    {
        return config("kol.platforms.{$this->platform}.label", ucfirst((string) $this->platform));
    }

    /** Link WhatsApp klik-untuk-chat dari No. HP. Normalkan 08xx → 62xx. null bila kosong. */
    public function whatsappUrl(): ?string
    {
        $d = preg_replace('/\D/', '', (string) $this->phone);
        if ($d === '') {
            return null;
        }
        if (str_starts_with($d, '0')) {
            $d = '62'.substr($d, 1);
        }

        return 'https://wa.me/'.$d;
    }

    /**
     * URL profil untuk klik username. Link manual (bila diisi) menang — bisa jadi
     * halaman spesifik yang sengaja dipilih; kalau kosong, dirakit dari platform +
     * handle. null bila platform tak punya templat DAN tak ada link manual.
     */
    public function profileUrl(): ?string
    {
        if (filled($this->tiktok_link)) {
            return $this->tiktok_link;
        }

        $tpl = config("kol.platforms.{$this->platform}.url");

        return $tpl ? sprintf($tpl, rawurlencode($this->handle())) : null;
    }

    /**
     * Level = turunan murni dari followers, TIDAK disimpan (dua sumber kebenaran
     * bakal selisih saat followers di-update).
     *
     * Batas mengikuti brief: Nano <10rb · Mikro 10rb–100rb · Middle 100rb–500rb ·
     * Makro 500rb–1jt · Mega 1jt–2,5jt · Super Mega >2,5jt. Angka batas masuk ke
     * jenjang ATASNYA (10.000 = Mikro, 100.000 = Middle, dst) — kecuali 2,5jt yang
     * masih Mega karena rentang Mega tertulis "1jt–2,5jt" (inklusif).
     */
    public function getLevelAttribute(): string
    {
        $f = (int) $this->followers;

        return match (true) {
            $f < 10_000 => 'Nano',
            $f < 100_000 => 'Mikro',
            $f < 500_000 => 'Middle',
            $f < 1_000_000 => 'Makro',
            $f <= 2_500_000 => 'Mega',
            default => 'Super Mega',
        };
    }

    public function screenings()
    {
        return $this->hasMany(KolScreening::class)->orderByDesc('tanggal_listing')->orderByDesc('id');
    }

    public function deals()
    {
        return $this->hasMany(KolDeal::class)->orderByDesc('id');
    }

    /** Screening terbaru — sumber kolom "verdict terakhir" di daftar KOL. */
    public function latestScreening()
    {
        return $this->hasOne(KolScreening::class)->latestOfMany('tanggal_listing');
    }

    /** Kartu pipeline scouting (satu per KOL, track kol). */
    public function pipelineCard()
    {
        return $this->hasOne(KolPipelineCard::class)->where('track', KolPipelineCard::TRACK_KOL);
    }

    /** Konten arsip KOL — dipakai reminder "affiliate berhenti posting". */
    public function contents()
    {
        return $this->hasMany(KolContent::class);
    }

    /** Log kontak (CRM) — histori komunikasi dengan KOL. */
    public function contactLogs()
    {
        return $this->hasMany(KolContactLog::class)->latest('contacted_at')->latest('id');
    }

    /** Jejak skor (APS/KSS) tersimpan. */
    public function scores()
    {
        return $this->hasMany(KolScore::class)->latest('captured_on')->latest('id');
    }

    /** Akun platform tambahan (akun utama tetap di kolom kols). */
    public function accounts()
    {
        return $this->hasMany(KolAccount::class)->orderBy('platform');
    }

    /** Rate card per tipe konten (append-only → riwayat, terbaru dulu). */
    public function rateCards()
    {
        return $this->hasMany(KolRateCard::class)->latest('id');
    }

    /** Gaji pokok bulanan (Tim Affiliate Gapok), terbaru dulu. */
    public function gapokSalaries()
    {
        return $this->hasMany(KolGapokSalary::class)->latest('period');
    }

    /** Hanya anggota Tim Affiliate Gapok. */
    public function scopeGapok($q)
    {
        return $q->where('is_gapok', true);
    }

    /** Snapshot performa TikTok Creator Marketplace (dari "Cek Performa TikTok"). */
    public function tiktokProfile()
    {
        return $this->hasOne(KolTiktokProfile::class);
    }
}
