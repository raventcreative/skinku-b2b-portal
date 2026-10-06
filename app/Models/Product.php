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
        'name', 'sku', 'category', 'description', 'image',
        'price_grand', 'price_distributor', 'price_reseller', 'price_retail', 'cogs',
        'weight_grams', 'hq_stock', 'hq_min_stock', 'status',
        'hpp_opening_qty', 'hpp_opening_cogs',
    ];

    protected function casts(): array
    {
        return [
            'price_grand' => 'decimal:2',
            'price_distributor' => 'decimal:2',
            'price_reseller' => 'decimal:2',
            'price_retail' => 'decimal:2',
            'cogs' => 'decimal:2',
            'weight_grams' => 'integer',
            'hq_stock' => 'integer',
            'hq_min_stock' => 'integer',
            'hpp_opening_qty' => 'integer',
            'hpp_opening_cogs' => 'decimal:2',
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
    /** Stok pusat ≤ stok minimum yang diisi (pengingat stok HQ). Minimum kosong/0 = tanpa pengingat. */
    public function isStokPusatMenipis(): bool
    {
        return (int) $this->hq_min_stock > 0 && (int) $this->hq_stock <= (int) $this->hq_min_stock;
    }

    /** Produk AKTIF yang stok pusatnya ≤ minimum — dasar banner Dashboard & saringan "stok pusat menipis". */
    public function scopeStokPusatMenipis($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)->where('hq_min_stock', '>', 0)->whereColumn('hq_stock', '<=', 'hq_min_stock');
    }

    public function priceForRole(string $role): float
    {
        return match ($role) {
            User::ROLE_GRAND_DISTRIBUTOR => (float) ($this->price_grand ?? $this->price_distributor),
            User::ROLE_DISTRIBUTOR => (float) $this->price_distributor,
            User::ROLE_RESELLER, User::ROLE_RESELLER_BRONZE, User::ROLE_RESELLER_GOLD => (float) $this->price_reseller,
            default => (float) $this->price_retail,
        };
    }
}
