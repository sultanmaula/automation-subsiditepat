<?php

namespace App\Filament\Workshop\Resources\Sales\Pages;

use App\Filament\Workshop\Resources\Sales\SaleResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Facades\FilamentView;
use Illuminate\Contracts\View\View;

class CreateSale extends CreateRecord
{
    protected static string $resource = SaleResource::class;

    public function getHeading(): string
    {
        return 'Transaksi Baru';
    }

    public function getFooter(): ?View
    {
        return view('filament.workshop.components.autofocus-barcode');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $items = $data['items'] ?? [];
        $total = collect($items)->sum(function (array $item): float {
            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);

            return $quantity * $unitPrice;
        });

        $paid = (float) ($data['paid'] ?? $total);

        $data['total'] = $total;
        $data['paid'] = $paid;
        $data['change'] = max($paid - $total, 0);
        $data['status'] = 'paid';

        return $data;
    }
}
