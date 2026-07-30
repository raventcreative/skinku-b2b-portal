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
        return in_array($this->role, [self::ROLE_DISTRIBUTOR, self::ROLE_RESELLER], true);
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
        return $this->role === self::ROLE_DISTRIBUTOR ? 'price_distributor' : 'price_reseller';
    }
}
