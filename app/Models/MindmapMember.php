<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MindmapMember extends Model
{
    protected $fillable = ['mindmap_id', 'user_id', 'can_edit'];

    protected function casts(): array
    {
        return ['can_edit' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
