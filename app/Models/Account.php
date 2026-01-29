<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Account extends Model
{
    protected $guarded = [];

    /* =========================
     * RELATIONSHIPS
     * ========================= */

    /**
     * Urutan dokumen khusus akun
     */
    public function documentOrders(): HasMany
    {
        return $this->hasMany(AccountDocumentOrder::class);
    }

    /**
     * Semua history input NIK akun
     */
    public function nikInputHistories(): HasMany
    {
        return $this->hasMany(NikInputHistory::class);
    }

    /**
     * OPTIONAL - legacy
     */
    public function dataNikInput()
    {
        return $this->hasOne(DataNikInput::class, 'nik', 'last_nik_input');
    }
}
