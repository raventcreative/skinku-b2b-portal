<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    public const KIND_RECEIPT = 'receipt';

    public const KIND_INVOICE = 'invoice';

    public const KIND_BANK = 'bank';

    public const KIND_TEXT = 'text';

    public const KINDS = [
        self::KIND_RECEIPT => 'Foto struk / nota',
        self::KIND_INVOICE => 'Invoice / PDF supplier',
        self::KIND_BANK => 'Screenshot mutasi bank / e-wallet',
        self::KIND_TEXT => 'Teks tempel (WhatsApp / catatan)',
    ];

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_EXTRACTED = 'extracted';

    public const STATUS_FAILED = 'failed';

    public const STATUS_POSTED = 'posted';

    protected $fillable = [
        'client_id', 'kind', 'title', 'original_name', 'disk', 'path', 'mime_type',
        'size', 'content_hash', 'raw_text', 'extraction', 'model_used', 'status',
        'error', 'uploaded_by', 'extracted_at',
    ];

    protected function casts(): array
    {
        return ['extraction' => 'array', 'extracted_at' => 'datetime'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function journals()
    {
        return $this->hasMany(Journal::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    /** Ada berkas yang bisa dibuka/ditampilkan (dokumen teks tempel tidak punya). */
    public function hasFile(): bool
    {
        return filled($this->path);
    }

    /** Isi berkas mentah, atau null kalau dokumen ini murni teks. */
    public function bytes(): ?string
    {
        if (blank($this->path)) {
            return null;
        }

        $disk = Storage::disk($this->disk ?: 'local');

        return $disk->exists($this->path) ? $disk->get($this->path) : null;
    }
}
