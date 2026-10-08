<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Client extends Model
{
    public const TYPE_INTERNAL = 'internal';

    public const TYPE_EXTERNAL = 'external';

    public const TYPES = [
        self::TYPE_INTERNAL => 'Brand / unit bisnis sendiri',
        self::TYPE_EXTERNAL => 'Klien jasa pembukuan',
    ];

    protected $fillable = [
        'name', 'slug', 'type', 'legal_name', 'npwp', 'contact_name',
        'phone', 'email', 'address', 'fiscal_year_start', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $client) {
            if (blank($client->slug)) {
                $client->slug = static::uniqueSlug($client->name, $client->id);
            }
        });
    }

    /** Slug yang dijamin unik — nambah sufiks angka kalau sudah dipakai klien lain. */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'klien';
        $slug = $base;
        $n = 2;
        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    public function journals()
    {
        return $this->hasMany(Journal::class);
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
