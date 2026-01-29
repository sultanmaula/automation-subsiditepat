<?php

namespace App\Filament\Resources\AccountDocumentOrders\Pages;

use App\Filament\Resources\AccountDocumentOrders\AccountDocumentOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccountDocumentOrders extends ListRecords
{
    protected static string $resource = AccountDocumentOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
