<?php

namespace App\Filament\Workshop\Resources\Products\Pages;

use App\Filament\Workshop\Resources\Products\ProductResource;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;
}
