<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Log kontak CRM dengan KOL (WA/DM/telp/email). Append-only. */
class KolContactLog extends Model
{
    public const UPDATED_AT = null;

    public const CHANNELS = ['wa', 'dm', 'telp', 'email', 'lainnya'];

    public const CHANNEL_LABELS = ['wa' => 'WhatsApp', 'dm' => 'DM', 'telp' => 'Telepon', 'email' => 'Email', 'lainnya' => 'Lainnya'];

    protected $fillable = ['kol_id', 'channel', 'note', 'contacted_at', 'created_by'];

    protected function casts(): array
    {
        return ['contacted_at' => 'date'];
    }

    public function kol()
    {
        return $this->belongsTo(Kol::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
