<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mindmap extends Model
{
    /** Palet warna sticky (kunci -> dipakai di UI). */
    public const COLORS = ['kuning', 'hijau', 'biru', 'rose', 'stone', 'putih'];

    protected $fillable = ['title', 'created_by'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members()
    {
        return $this->hasMany(MindmapMember::class);
    }

    public function nodes()
    {
        return $this->hasMany(MindmapNode::class);
    }

    public function edges()
    {
        return $this->hasMany(MindmapEdge::class);
    }

    public function isOwner(User $user): bool
    {
        return $user->isSuperAdmin() || $this->created_by === $user->id;
    }

    public function canView(User $user): bool
    {
        return $this->isOwner($user)
            || $this->members()->where('user_id', $user->id)->exists();
    }

    public function canEdit(User $user): bool
    {
        return $this->isOwner($user)
            || $this->members()->where('user_id', $user->id)->where('can_edit', true)->exists();
    }
}
