<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    protected $fillable = [
        'module_id', 'title', 'description', 'video_url', 'category',
        'audience', 'sort_order', 'is_published', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'sort_order' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    /** Extract the 11-char YouTube video id from common URL formats. */
    public function youtubeId(): ?string
    {
        if (! $this->video_url) {
            return null;
        }
        if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/|v/|live/))([A-Za-z0-9_-]{11})~', $this->video_url, $m)) {
            return $m[1];
        }

        return null;
    }

    public function embedUrl(): ?string
    {
        $id = $this->youtubeId();

        return $id ? "https://www.youtube.com/embed/{$id}" : null;
    }

    public function thumbnailUrl(): ?string
    {
        $id = $this->youtubeId();

        return $id ? "https://img.youtube.com/vi/{$id}/hqdefault.jpg" : null;
    }

    /** Is this lesson visible to the given user? */
    public function visibleTo(User $user): bool
    {
        if (! $this->is_published && ! $user->canDo('manage_learning')) {
            return false;
        }
        if ($user->isSuperAdmin() || empty($this->audience)) {
            return true;
        }

        return in_array($user->role, $this->audience, true);
    }

    public function module()
    {
        return $this->belongsTo(LearningModule::class, 'module_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
