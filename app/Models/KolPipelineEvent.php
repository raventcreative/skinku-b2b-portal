<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Log perpindahan stage — append-only, tidak pernah di-update/hapus. */
class KolPipelineEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['card_id', 'from_stage', 'to_stage', 'note', 'created_by'];

    public function card()
    {
        return $this->belongsTo(KolPipelineCard::class, 'card_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
