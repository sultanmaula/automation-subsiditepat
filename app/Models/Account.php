<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Account extends Model
{
    protected $guarded = [];

    public function dataNikInput(): HasOne
    {
        return $this->hasOne(DataNikInput::class, 'nik', 'last_nik_input');
    }
}
