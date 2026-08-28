<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Log satu batch import transaksi affiliate. */
class KolImportBatch extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['platform', 'source', 'filename', 'imported', 'matched', 'unmatched', 'created_by'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
