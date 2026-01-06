<?php

namespace App\Models\Workshop;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $table = 'workshop_stock_movements';

    protected $fillable = [
        'product_id',
        'type',
        'quantity',
        'unit_cost',
        'reference_type',
        'reference_id',
        'note',
        'created_by',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (StockMovement $movement): void {
            if (empty($movement->created_by) && auth()->check()) {
                $movement->created_by = auth()->id();
            }
        });

        static::created(function (StockMovement $movement): void {
            $product = $movement->product;

            if (! $product) {
                return;
            }

            $delta = match ($movement->type) {
                'in' => $movement->quantity,
                'out' => -$movement->quantity,
                default => $movement->quantity,
            };

            if ($delta >= 0) {
                $product->increment('stock', $delta);
                return;
            }

            $product->decrement('stock', abs($delta));
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
