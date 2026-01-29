<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NikInputHistory extends Model
{
    protected $guarded = [];

    protected $casts = [
        'input_date' => 'date',
    ];

    /* =========================
     * RELATIONSHIPS
     * ========================= */

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(DataMasterDocument::class, 'data_master_document_id');
    }

    public function dataNikInput(): BelongsTo
    {
        return $this->belongsTo(DataNikInput::class);
    }
}
