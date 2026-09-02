<?php

namespace App\Models\Workshop;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $table = 'workshop_products';

    protected $fillable = [
        'category_id',
        'name',
        'barcode',
        'unit',
        'cost_price',
        'sale_price',
        'stock',
        'min_stock',
        'is_active',
        'description',
        'is_quick_sale',
        'quick_sort',
        'location',
        'compatible_models',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'is_active' => 'boolean',
        'is_quick_sale' => 'boolean',
        'compatible_models' => 'array',
    ];

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

        if ($this->barcode) {
            $parts[] = $this->barcode;
        }

        return implode(' | ', $parts);
    }
}
