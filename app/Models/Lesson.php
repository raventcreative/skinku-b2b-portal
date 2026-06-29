<?php

namespace App\Models;

use App\Models\Concerns\HasFiles;
use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    use HasFiles;

    public const TYPE_VIDEO = 'video';

    public const TYPE_DOCUMENT = 'document';

    public const COVER = 'lesson_cover';   // optional thumbnail image

    public const DOC = 'lesson_file';      // the PPT/Word/PDF file

    protected $fillable = [
        'module_id', 'type', 'title', 'description', 'video_url', 'category',
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

    /** A materi can have a video and/or a document — both are optional. */
    public function isVideo(): bool
    {
        return ! empty($this->video_url);
    }

    public function isDocument(): bool
    {
        return $this->documentFile() !== null;
    }

    /** Card thumbnail: YouTube thumb if video, else uploaded cover. */
    public function thumbnailUrl(): ?string
    {
        if ($this->isVideo() && $this->youtubeId()) {
            return "https://img.youtube.com/vi/{$this->youtubeId()}/hqdefault.jpg";
        }

        return $this->firstFileUrl(self::COVER);
    }

    public function documentFile(): ?File
    {
        return $this->filesIn(self::DOC)->first();
    }

    public function documentUrl(): ?string
    {
        $f = $this->documentFile();

        return $f ? url($f->url()) : null; // absolute (needed for Office viewer)
    }

    public function documentName(): ?string
    {
        return $this->documentFile()?->original_name;
    }

    public function documentExtension(): ?string
    {
        $f = $this->documentFile();

        return $f ? strtolower(pathinfo($f->path, PATHINFO_EXTENSION)) : null;
    }

    public function isPdf(): bool
    {
        return $this->documentExtension() === 'pdf';
    }

    /** In-browser preview URL for the document. */
    public function previewUrl(): ?string
    {
        $url = $this->documentUrl();
        if (! $url) {
            return null;
        }
        if ($this->isPdf()) {
            return $url; // browsers render PDF natively in an iframe
        }

        // PPT/Word/Excel via Microsoft Office Online viewer (file must be public).
        return 'https://view.officeapps.live.com/op/embed.aspx?src='.urlencode($url);
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
