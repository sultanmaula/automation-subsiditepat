<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataMasterDocument extends Model
{
    protected $guarded = [];

    /* =========================
     * RELATIONSHIPS
     * ========================= */

    /**
     * Semua NIK dalam dokumen
     */
    public function dataNikInputs(): HasMany
    {
        return $this->hasMany(DataNikInput::class);
    }

    /**
     * Urutan dokumen di berbagai akun
     */
    public function accountOrders(): HasMany
    {
        return $this->hasMany(AccountDocumentOrder::class);
    }

    /**
     * History input NIK
     */
    public function nikInputHistories(): HasMany
    {
        return $this->hasMany(NikInputHistory::class);
    }
}
