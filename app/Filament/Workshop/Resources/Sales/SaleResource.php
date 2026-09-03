<?php

namespace App\Filament\Workshop\Resources\Sales;

use App\Filament\Workshop\Resources\Sales\Pages\CreateSale;
use App\Filament\Workshop\Resources\Sales\Pages\ListSales;
use App\Filament\Workshop\Resources\Sales\Pages\ViewSale;
use App\Filament\Workshop\Resources\Sales\Schemas\SaleForm;
use App\Filament\Workshop\Resources\Sales\Schemas\SaleInfolist;
use App\Filament\Workshop\Resources\Sales\Tables\SalesTable;
use App\Models\Workshop\Sale;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class SaleResource extends Resource
{
    protected static ?string $model = Sale::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static string | \UnitEnum | null $navigationGroup = 'Kasir';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'sale_number';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('sales') ?? false;
    }

    public static function getModelLabel(): string { return 'Transaksi'; }

    public static function getPluralModelLabel(): string { return 'Transaksi'; }

    public static function getNavigationLabel(): string { return 'Transaksi'; }

    public static function form(Schema $schema): Schema
    {
        return SaleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SaleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSales::route('/'),
            'create' => CreateSale::route('/create'),
            'view' => ViewSale::route('/{record}'),
        ];
    }
}
