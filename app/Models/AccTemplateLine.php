<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccTemplateLine extends Model
{
    protected $table = 'acc_template_lines';

    protected $fillable = ['acc_template_id', 'account_id', 'side', 'sort_order'];

    public function template()
    {
        return $this->belongsTo(AccTemplate::class, 'acc_template_id');
    }

    public function account()
    {
        return $this->belongsTo(AccAccount::class, 'account_id');
    }
}
