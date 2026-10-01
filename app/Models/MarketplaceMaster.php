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

    protected $fillable = ['parent_id', 'variant_type', 'variant_name', 'master_sku', 'name', 'name_key', 'is_bundle', 'image_url', 'product_id', 'base_stock', 'base_price', 'seeded_at', 'category', 'description', 'weight_g', 'length_cm', 'width_cm', 'height_cm', 'barcode',
        'tiktok_category_id', 'tiktok_category_name', 'tiktok_attributes', 'shopee_category_id', 'shopee_category_name', 'shopee_attributes', 'shopee_brand'];

    protected function casts(): array
    {
        return [
            'is_bundle' => 'boolean',
            'base_stock' => 'integer',
            'base_price' => 'decimal:2',
            'seeded_at' => 'datetime',
            'tiktok_attributes' => 'array',
            'shopee_attributes' => 'array',
            'shopee_brand' => 'array',
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

    /** Induk (bila master ini satu opsi varian). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Opsi varian (master anak) — urut dibuat. */
    public function variants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('id');
    }

    /** Sumber konten level PRODUK (nama/foto/deskripsi/berat/dimensi/kategori): induk bila varian, else diri sendiri. */
    public function sumberKonten(): self
    {
        return $this->parent_id ? ($this->parent ?? $this) : $this;
    }

    /** id induk + semua anaknya (satu "keluarga" produk). */
    public function keluargaIds(): array
    {
        $root = $this->sumberKonten();

        return array_merge([$root->id], $root->variants()->pluck('id')->all());
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
        return $this->firstFileUrl(self::MASTER_IMAGE) ?? $this->image_url ?? ($this->parent_id ? $this->parent?->imageUrl() : null);
    }
}
