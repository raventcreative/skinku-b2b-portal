<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberDormancyRule extends Model
{
    public const BASIS_ORDER = 'order';

    public const BASIS_LOGIN = 'login';

    public const BASIS_RECRUIT = 'recruit';

    public const BASES = [self::BASIS_ORDER, self::BASIS_LOGIN, self::BASIS_RECRUIT];

    protected $fillable = ['role', 'enabled', 'inactive_months', 'basis', 'activated_at', 'updated_by'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'inactive_months' => 'integer',
            'activated_at' => 'datetime',
        ];
    }
}
