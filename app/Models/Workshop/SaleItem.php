<?php

namespace App\Models\Workshop;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    protected $table = 'workshop_sale_items';

    protected $fillable = [
        'sale_id',
        'product_id',
        'quantity',
        'unit_price',
        'subtotal',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (SaleItem $item): void {
            if (empty($item->unit_price)) {
                $product = $item->product ?? Product::find($item->product_id);
                $item->unit_price = $product?->sale_price ?? 0;
            }

            $item->subtotal = $item->quantity * $item->unit_price;
        });

        static::created(function (SaleItem $item): void {
            StockMovement::create([
                'product_id' => $item->product_id,
                'type' => 'out',
                'quantity' => $item->quantity,
                'unit_cost' => $item->unit_price,
                'reference_type' => Sale::class,
                'reference_id' => $item->sale_id,
                'note' => 'Penjualan ' . ($item->sale?->sale_number ?? '-'),
            ]);

            $item->sale?->recalculateTotals();
        });
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
