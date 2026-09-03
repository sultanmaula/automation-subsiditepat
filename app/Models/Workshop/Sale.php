<?php

namespace App\Models\Workshop;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Sale extends Model
{
    protected $table = 'workshop_sales';

    protected $fillable = [
        'sale_number',
        'cashier_id',
        'customer_name',
        'payment_method',
        'total',
        'paid',
        'change',
        'status',
        'payment_status',
        'qris_transaction_id',
        'qris_qr_url',
        'qris_checkout_url',
        'qris_expires_at',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'paid' => 'decimal:2',
        'change' => 'decimal:2',
        'qris_expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Sale $sale): void {
            if (empty($sale->sale_number)) {
                $sale->sale_number = self::generateSaleNumber();
            }

            if (empty($sale->cashier_id) && auth()->check()) {
                $sale->cashier_id = auth()->id();
            }
        });

        static::retrieved(function (Sale $sale): void {
            // Auto-check dan update status expired saat record di-retrieve
            $sale->checkAndUpdateQrisExpired();
        });
    }

    public static function generateSaleNumber(): string
    {
        return 'WS-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6));
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'cashier_id');
    }

    public function recalculateTotals(): void
    {
        $total = $this->items()->sum('subtotal');
        $paid = $this->paid ?? $total;
        $change = max($paid - $total, 0);

        $this->forceFill([
            'total' => $total,
            'change' => $change,
        ])->save();
    }

    public function isQrisExpired(): bool
    {
        return $this->payment_method === 'qris'
            && $this->payment_status === 'pending'
            && $this->qris_expires_at
            && now()->isAfter($this->qris_expires_at);
    }

    public function checkAndUpdateQrisExpired(): void
    {
        if ($this->isQrisExpired()) {
            $this->update([
                'payment_status' => 'expired',
                'status' => 'expired',
            ]);
        }
    }
}
