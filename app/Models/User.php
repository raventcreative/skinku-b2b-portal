<?php

namespace App\Models;

use App\Support\Permissions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    /** Canonical roles used across the portal. */
    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_GUDANG = 'gudang';

    public const ROLE_DISTRIBUTOR = 'distributor';

    public const ROLE_RESELLER = 'reseller';

    public const ROLE_GRAND_DISTRIBUTOR = 'grand_distributor';

    public const ROLE_RESELLER_BRONZE = 'reseller_bronze';

    public const ROLE_RESELLER_GOLD = 'reseller_gold';

    /** Sponsor = perekrut murni (punya saldo/komisi + withdraw, TANPA stok/PO). Bukan tier pasok. */
    public const ROLE_SPONSOR = 'sponsor';

    /** Semua role yang dianggap "mitra" (lama + tier MLM + sponsor). */
    public const PARTNER_ROLES = [
        self::ROLE_DISTRIBUTOR, self::ROLE_RESELLER,
        self::ROLE_GRAND_DISTRIBUTOR, self::ROLE_RESELLER_BRONZE, self::ROLE_RESELLER_GOLD,
        self::ROLE_SPONSOR,
    ];

    public const ROLES = [
        self::ROLE_SUPER_ADMIN,
        self::ROLE_ADMIN,
        self::ROLE_GUDANG,
        self::ROLE_DISTRIBUTOR,
        self::ROLE_RESELLER,
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_DELETED = 'deleted';

    protected $fillable = [
        'uid', 'name', 'fullname', 'email', 'username', 'password',
        'role', 'company_name', 'phone', 'address', 'status', 'region',
        'upline_id', 'sponsor_id', 'member_id',
        'bank', 'no_rekening', 'atas_nama',
        'email_verified_at', 'disabled_at', 'created_by', 'updated_by',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'disabled_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /* --------------------------------------------------------------------- */
    /* Relationships */
    /* --------------------------------------------------------------------- */

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class, 'user_id');
    }

    public function inventory()
    {
        return $this->hasMany(Inventory::class, 'user_id');
    }

    public function upline()
    {
        return $this->belongsTo(User::class, 'upline_id');
    }

    public function downlines()
    {
        return $this->hasMany(User::class, 'upline_id');
    }

    /** Jalur rekrutmen: siapa yang MEREKRUT member ini (beda dari upline pasok). */
    public function sponsor()
    {
        return $this->belongsTo(User::class, 'sponsor_id');
    }

    /** Member yang DIREKRUT user ini (lead-nya). */
    public function recruits()
    {
        return $this->hasMany(User::class, 'sponsor_id');
    }

    /** Transaksi join aktif (belum dibatalkan) — dasar tombol "Batal Join". */
    public function activeJoinTransaction()
    {
        return $this->hasOne(JoinTransaction::class, 'user_id')->whereNull('cancelled_at')->latest('id');
    }

    /* --------------------------------------------------------------------- */
    /* Role / status helpers */
    /* --------------------------------------------------------------------- */

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /** Management = full back-office (super_admin or admin). */
    public function isManagement(): bool
    {
        return in_array($this->role, [self::ROLE_SUPER_ADMIN, self::ROLE_ADMIN], true);
    }

    public function isGudang(): bool
    {
        return $this->role === self::ROLE_GUDANG;
    }

    /** Staff = anyone working HQ-side (management + warehouse). */
    public function isStaff(): bool
    {
        return $this->isManagement() || $this->isGudang();
    }

    public function isPartner(): bool
    {
        return in_array($this->role, self::PARTNER_ROLES, true);
    }

    public function hasRole(string|array $roles): bool
    {
        return in_array($this->role, (array) $roles, true);
    }

    /** Configurable capability check (super_admin always true). */
    public function canDo(string $permission): bool
    {
        return Permissions::roleHas($this->role, $permission);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function displayName(): string
    {
        return $this->fullname ?: ($this->name ?: $this->username);
    }

    /** Unit price field that applies to this partner's role. */
    public function priceField(): string
    {
        return in_array($this->role, [self::ROLE_DISTRIBUTOR, self::ROLE_GRAND_DISTRIBUTOR], true)
            ? 'price_distributor' : 'price_reseller';
    }
}
