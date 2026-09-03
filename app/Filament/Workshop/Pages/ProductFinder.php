<?php

namespace App\Filament\Workshop\Pages;

use App\Models\Workshop\Product;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

class ProductFinder extends Page
{
    protected string $view = 'filament.workshop.pages.product-finder';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationLabel = 'Cari Barang';

    protected static ?string $title = 'Cari Barang';

    protected static string | \UnitEnum | null $navigationGroup = 'Kasir';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('finder') ?? false;
    }

    #[Url(as: 'q')]
    public string $search = '';

    #[Computed]
    public function results(): Collection
    {
        $query = trim($this->search);

        if (strlen($query) < 2) {
            return collect();
        }

        return Product::query()
            ->where('is_active', true)
            ->where(function ($q) use ($query): void {
                $q->where('name', 'ilike', "%{$query}%")
                    ->orWhere('barcode', 'ilike', "%{$query}%")
                    ->orWhere('location', 'ilike', "%{$query}%")
                    ->orWhereRaw(
                        "EXISTS (SELECT 1 FROM jsonb_array_elements_text(compatible_models) m WHERE m ILIKE ?)",
                        ["%{$query}%"]
                    );
            })
            ->with('category')
            ->orderByRaw('location IS NULL ASC')
            ->orderBy('name')
            ->limit(30)
            ->get();
    }

    #[Computed]
    public function alternatives(): Collection
    {
        $results = $this->results;

        if ($results->isEmpty()) {
            return collect();
        }

        $resultIds = $results->pluck('id')->all();

        $productsWithModels = $results->filter(
            fn ($p) => ! empty($p->compatible_models)
        );

        if ($productsWithModels->isEmpty()) {
            return collect();
        }

        $allModels = $productsWithModels->flatMap(
            fn ($p) => $p->compatible_models
        )->unique()->values()->all();

        if (empty($allModels)) {
            return collect();
        }

        $placeholders = implode(',', array_fill(0, count($allModels), '?'));

        return Product::query()
            ->where('is_active', true)
            ->whereIn('category_id', $results->pluck('category_id')->filter()->unique()->all())
            ->whereNotIn('id', $resultIds)
            ->whereRaw(
                "EXISTS (SELECT 1 FROM jsonb_array_elements_text(compatible_models) m WHERE m IN ({$placeholders}))",
                $allModels
            )
            ->with('category')
            ->orderBy('name')
            ->limit(5 * count($results))
            ->get()
            ->groupBy('category_id');
    }
}
