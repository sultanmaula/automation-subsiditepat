<?php

namespace App\Filament\Resources\AccountDocumentOrders\Pages;

use App\Filament\Resources\AccountDocumentOrders\AccountDocumentOrderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAccountDocumentOrder extends EditRecord
{
    protected static string $resource = AccountDocumentOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
