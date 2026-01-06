<?php

namespace App\Filament\Workshop\Resources\StockMovements;

use App\Filament\Workshop\Resources\StockMovements\Pages\CreateStockMovement;
use App\Filament\Workshop\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Workshop\Resources\StockMovements\Pages\ViewStockMovement;
use App\Filament\Workshop\Resources\StockMovements\Schemas\StockMovementForm;
use App\Filament\Workshop\Resources\StockMovements\Tables\StockMovementsTable;
use App\Models\Workshop\StockMovement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

    // protected static string|BackedEnum|null $navigationGroup = 'Inventory';

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return StockMovementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockMovementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockMovements::route('/'),
            'create' => CreateStockMovement::route('/create'),
            'view' => ViewStockMovement::route('/{record}'),
        ];
    }
}
