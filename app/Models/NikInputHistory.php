<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NikInputHistory extends Model
{
    protected $guarded = [];

    protected $casts = [
        'input_date'  => 'date',
        'is_failed'   => 'boolean',
        'rejected_at' => 'datetime',
    ];

    /* =========================
     * INVARIANT
     * ========================= */

    /**
     * Baris ber-`rejected_status` SELALU is_failed = true.
     *
     * Penolakan Pertamina (TRANSACTION_INVALID / TRANSACTION_ANOMALY dst)
     * bukan pembelian. Beberapa penghitung lama hanya memfilter
     * `is_failed = false` tanpa melihat rejected_status, sehingga baris
     * ditolak sempat terhitung sukses. Invarian dijaga di model supaya
     * berlaku untuk SEMUA jalur tulis (job, command, panel admin).
     */
    protected static function booted(): void
    {
        static::saving(function (self $history): void {
            if (filled($history->rejected_status)) {
                $history->is_failed = true;
                $history->rejected_at ??= now();
            }
        });
    }

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
