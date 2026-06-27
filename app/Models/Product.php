<?php

namespace App\Models;

use App\Models\Concerns\HasFiles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, HasFiles, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_DELETED = 'deleted';

    /** File collection name for product photos. */
    public const GALLERY = 'product_gallery';

    protected $fillable = [
        'name', 'sku', 'category', 'description',
        'price_distributor', 'price_reseller', 'price_retail', 'cogs',
        'hq_stock', 'status',
    ];

    protected function casts(): array
    {
        return [
            'price_distributor' => 'decimal:2',
            'price_reseller' => 'decimal:2',
            'price_retail' => 'decimal:2',
            'cogs' => 'decimal:2',
            'hq_stock' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Primary product image URL (first gallery photo from the files table). */
    public function imageUrl(): ?string
    {
        return $this->firstFileUrl(self::GALLERY);
    }

    /** All gallery image URLs. */
    public function imageUrls(): array
    {
        return $this->fileUrls(self::GALLERY);
    }

    /** Returns the unit price for a given role. */
    public function priceForRole(string $role): float
    {
        return match ($role) {
            User::ROLE_DISTRIBUTOR => (float) $this->price_distributor,
            User::ROLE_RESELLER => (float) $this->price_reseller,
            default => (float) $this->price_retail,
        };
    }
}
