<?php

namespace App\Models;

use App\Models\Concerns\HasFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MarketplaceMaster extends Model
{
    use HasFiles;

    /** File collection name for the manually-uploaded master photo. */
    public const MASTER_IMAGE = 'master_image';

    protected $fillable = ['master_sku', 'name', 'name_key', 'is_bundle', 'image_url', 'product_id', 'base_stock', 'base_price', 'seeded_at'];

    protected function casts(): array
    {
        return [
            'is_bundle' => 'boolean',
            'base_stock' => 'integer',
            'base_price' => 'decimal:2',
            'seeded_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(MarketplaceMasterChannel::class, 'master_id');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class, 'master_id');
    }

    public static function normalizeName(string $name): string
    {
        return Str::of($name)->lower()->squish()->toString();
    }

    public static function detectBundle(?string $name): bool
    {
        return $name !== null && preg_match('/bundl|paket/i', $name) === 1;
    }

    /** URL foto: upload manual (koleksi master_image) menang, else image_url dari marketplace. */
    public function imageUrl(): ?string
    {
        return $this->firstFileUrl(self::MASTER_IMAGE) ?? $this->image_url;
    }
}
