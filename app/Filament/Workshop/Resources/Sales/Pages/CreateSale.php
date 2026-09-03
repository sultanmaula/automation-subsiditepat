<?php

namespace App\Filament\Workshop\Resources\Sales\Pages;

use App\Filament\Workshop\Resources\Sales\SaleResource;
use App\Models\Workshop\Category;
use App\Models\Workshop\Product;
use App\Services\AutoGoPayService;
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
        $isQris = ($data['payment_method'] ?? 'cash') === 'qris';

        $data['paid']           = $isQris ? 0 : (float) ($data['paid'] ?? 0);
        $data['total']          = 0;
        $data['change']         = 0;
        $data['status']         = $isQris ? 'pending' : 'paid';
        $data['payment_status'] = $isQris ? 'pending' : 'paid';

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->record;
        $total  = (float) $record->items()->sum(\Illuminate\Support\Facades\DB::raw('quantity * unit_price'));
        $isQris = $record->payment_method === 'qris';

        $paid   = $isQris ? $total : (float) $record->paid;
        $change = $isQris ? 0 : max($paid - $total, 0);

        $record->update([
            'total'  => $total,
            'paid'   => $paid,
            'change' => $change,
        ]);

        if ($isQris) {
            $qrisData = app(AutoGoPayService::class)->generateQris((int) $total);

            if ($qrisData) {
                $record->update([
                    'qris_transaction_id' => $qrisData['transaction_id'] ?? null,
                    'qris_qr_url'         => $qrisData['qr_url'] ?? null,
                    'qris_checkout_url'   => $qrisData['checkout_url'] ?? null,
                    'qris_expires_at'     => now()->addMinutes(15),
                ]);
            } else {
                Notification::make()
                    ->title('Gagal membuat QRIS')
                    ->body('Cek koneksi atau API key AutoGoPay.')
                    ->danger()
                    ->send();
            }
        }

        // QRIS: buka nota tanpa auto-print (tunggu pembayaran)
        // Cash: buka nota dengan auto-print langsung
        $notaUrl = route('workshop.nota', $record->id) . ($isQris ? '' : '?auto=1');
        $this->js("window.open('{$notaUrl}', '_blank')");
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
