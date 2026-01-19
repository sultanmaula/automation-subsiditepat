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
