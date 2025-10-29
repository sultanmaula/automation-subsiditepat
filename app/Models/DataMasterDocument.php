<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataMasterDocument extends Model
{
    protected $guarded = [];

    public function dataNikInputs(): HasMany
    {
        return $this->hasMany(DataNikInput::class);
    }
}
