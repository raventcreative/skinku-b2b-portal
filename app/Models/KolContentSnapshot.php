<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Snapshot views bertanggal — append-only; satu baris per konten per hari. */
class KolContentSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['kol_content_id', 'views', 'likes', 'comments', 'shares', 'saves', 'captured_on', 'source', 'created_by'];

    protected function casts(): array
    {
        return ['captured_on' => 'date'];
    }

    public function content()
    {
        return $this->belongsTo(KolContent::class, 'kol_content_id');
    }
}
