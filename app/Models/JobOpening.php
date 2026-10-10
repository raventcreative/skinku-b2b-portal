<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Lowongan kerja (menu HR → Rekrutmen). */
class JobOpening extends Model
{
    public const STATUSES = ['buka' => 'Buka', 'tutup' => 'Tutup'];

    protected $fillable = ['title', 'department', 'status', 'description', 'created_by'];

    public function candidates()
    {
        return $this->hasMany(Candidate::class);
    }
}
