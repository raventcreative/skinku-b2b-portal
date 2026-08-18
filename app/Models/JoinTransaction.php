<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JoinTransaction extends Model
{
    protected $fillable = ['user_id', 'join_package_id', 'inviter_id', 'price', 'created_by'];

    protected $casts = ['price' => 'decimal:2'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(JoinPackage::class, 'join_package_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }
}
