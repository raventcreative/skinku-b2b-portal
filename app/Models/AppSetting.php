<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Setelan aplikasi sederhana (key/value). Dipakai untuk hal global yang tak
 * punya rumah alami — mis. batas tanggal potong stok PO.
 */
class AppSetting extends Model
{
    /** Batas tanggal potong stok PO: order sebelum tanggal ini TIDAK memotong stok. */
    public const PO_DEDUCT_FROM = 'po_deduct_from';

    protected $table = 'app_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::find($key)?->value ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** Setelan tanggal → Carbon (null kalau kosong/tak valid). */
    public static function date(string $key): ?Carbon
    {
        $v = static::get($key);
        if (! $v) {
            return null;
        }

        try {
            return Carbon::parse($v)->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
