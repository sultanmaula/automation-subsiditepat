<?php

namespace App\Filament\Workshop\Resources\StockMovements\Pages;

use App\Filament\Workshop\Resources\StockMovements\StockMovementResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStockMovement extends CreateRecord
{
    protected static string $resource = StockMovementResource::class;
}
