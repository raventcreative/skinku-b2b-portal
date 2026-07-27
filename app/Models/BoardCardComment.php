<?php

namespace App\Models;

use App\Support\Text;
use Illuminate\Database\Eloquent\Model;

class BoardCardComment extends Model
{
    protected $fillable = ['card_id', 'user_id', 'body'];

    /** Isi komentar siap tampil: aman XSS + URL http(s) jadi tautan klik + newline jadi <br>. */
    public function bodyHtml(): string
    {
        return Text::linkify($this->body);
    }

    public function card()
    {
        return $this->belongsTo(BoardCard::class, 'card_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
