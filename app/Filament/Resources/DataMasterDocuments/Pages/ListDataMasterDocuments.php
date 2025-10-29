<?php

namespace App\Filament\Resources\DataMasterDocuments\Pages;

use App\Filament\Resources\DataMasterDocuments\DataMasterDocumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDataMasterDocuments extends ListRecords
{
    protected static string $resource = DataMasterDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
