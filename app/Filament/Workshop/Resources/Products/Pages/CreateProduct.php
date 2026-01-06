<?php

namespace App\Filament\Workshop\Resources\Products\Pages;

use App\Filament\Workshop\Resources\Products\ProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;
}
