<?php

namespace App\Filament\Workshop\Resources\Sales\Pages;

use App\Filament\Workshop\Resources\Sales\SaleResource;
use App\Models\Workshop\Category;
use App\Models\Workshop\Product;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\View\View;

class CreateSale extends CreateRecord
{
    protected static string $resource = SaleResource::class;

    public function getHeading(): string
    {
        return 'Transaksi Baru';
    }

    public function getHeader(): ?View
    {
        $quickProducts = Product::query()
            ->where('is_quick_sale', true)
            ->where('is_active', true)
            ->with('category')
            ->orderBy('quick_sort')
            ->orderBy('name')
            ->get();

        $quickCategories = Category::query()
            ->whereHas('products', fn ($q) => $q->where('is_quick_sale', true)->where('is_active', true))
            ->orderBy('name')
            ->get();

        return view('filament.workshop.components.quick-products', [
            'quickProducts' => $quickProducts,
            'quickCategories' => $quickCategories,
        ]);
    }

    public function getFooter(): ?View
    {
        return view('filament.workshop.components.autofocus-barcode');
    }

    public function addQuickProduct(int $productId): void
    {
        $product = Product::find($productId);

        if (! $product || ! $product->is_active) {
            return;
        }

        $items = $this->data['items'] ?? [];
        $updated = false;

        foreach ($items as $index => $item) {
            if (($item['product_id'] ?? null) == $product->id) {
                $quantity = (int) ($item['quantity'] ?? 0) + 1;
                $unitPrice = (float) ($item['unit_price'] ?? $product->sale_price);
                $items[$index]['quantity'] = $quantity;
                $items[$index]['unit_price'] = $unitPrice;
                $items[$index]['subtotal'] = $quantity * $unitPrice;
                $updated = true;
                break;
            }
        }

        if (! $updated) {
            $items[] = [
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => (float) $product->sale_price,
                'subtotal' => (float) $product->sale_price,
            ];
        }

        $this->data['items'] = array_values($items);

        $notification = Notification::make()
            ->title($product->name . ' ditambahkan')
            ->success()
            ->duration(2500);

        if ($product->location) {
            $notification->body('📍 ' . $product->location);
        }

        $notification->send();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Parse paid - nilai sudah di-dehydrate oleh form jadi integer
        $paid = (float) ($data['paid'] ?? 0);

        // Set nilai awal, total akan dihitung di afterCreate
        $data['paid'] = $paid;
        $data['total'] = 0;
        $data['change'] = 0;
        $data['status'] = 'paid';

        return $data;
    }

    protected function afterCreate(): void
    {
        // Hitung total dari items yang sudah tersimpan
        $record = $this->record;
        $total = (float) $record->items()->sum(\Illuminate\Support\Facades\DB::raw('quantity * unit_price'));

        $paid = (float) $record->paid;
        $change = max($paid - $total, 0);

        $record->update([
            'total' => $total,
            'change' => $change,
        ]);
    }
}
