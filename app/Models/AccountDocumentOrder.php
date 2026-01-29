<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountDocumentOrder extends Model
{
    protected $guarded = [];

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
}
