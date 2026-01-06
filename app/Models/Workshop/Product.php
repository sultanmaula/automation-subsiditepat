<?php

namespace App\Models\Workshop;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Product extends Model
{
    protected $table = 'workshop_products';

    protected $fillable = [
        'category_id',
        'name',
        'sku',
        'barcode',
        'unit',
        'cost_price',
        'sale_price',
        'stock',
        'min_stock',
        'is_active',
        'description',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            if (empty($product->sku)) {
                $product->sku = self::generateSku();
            }
        });
    }

    public static function generateSku(): string
    {
        return 'PRD-' . Str::upper(Str::random(8));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'product_id');
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'product_id');
    }

    public function getLabelAttribute(): string
    {
        $parts = [$this->name];

        if ($this->sku) {
            $parts[] = $this->sku;
        }

        if ($this->barcode) {
            $parts[] = $this->barcode;
        }

        return implode(' | ', $parts);
    }
}
