<?php

namespace App\Filament\Workshop\Pages;

use App\Models\Workshop\Product;
use App\Models\Workshop\StockMovement;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

class StockOpname extends Page
{
    protected string $view = 'filament.workshop.pages.stock-opname';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Stock Opname';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('stock') ?? false;
    }

    protected static ?string $title = 'Stock Opname';

    protected static ?int $navigationSort = 4;

    public ?int $productId = null;

    public string $actualCount = '';

    public string $reason = '';

    #[Computed]
    public function selectedProduct(): ?Product
    {
        if (! $this->productId) {
            return null;
        }

        return Product::find($this->productId);
    }

    #[Computed]
    public function products(): Collection
    {
        return Product::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'stock']);
    }

    #[Computed]
    public function recentAdjustments(): Collection
    {
        return StockMovement::with('product:id,name', 'creator:id,name')
            ->where('type', 'opname')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();
    }

    public function adjustStock(): void
    {
        $product = $this->selectedProduct;

        if (! $product || $this->actualCount === '') {
            Notification::make()->title('Pilih produk dan isi jumlah stok aktual')->warning()->send();
            return;
        }

        $actual = (int) $this->actualCount;
        $delta  = $actual - $product->stock;

        if ($delta === 0) {
            Notification::make()->title('Stok sudah sesuai')->body("{$product->name}: {$product->stock} unit — tidak ada perubahan.")->info()->send();
            return;
        }

        StockMovement::create([
            'product_id' => $product->id,
            'type'       => 'opname',
            'quantity'   => $delta,
            'note'       => $this->reason ?: 'Stock opname',
        ]);

        $sign = $delta > 0 ? '+' : '';
        Notification::make()
            ->title("Stok {$product->name} diperbarui")
            ->body("Sistem: {$product->stock} → Aktual: {$actual} (selisih: {$sign}{$delta})")
            ->success()
            ->send();

        $this->productId   = null;
        $this->actualCount = '';
        $this->reason      = '';
        unset($this->selectedProduct, $this->products, $this->recentAdjustments);
    }
}
