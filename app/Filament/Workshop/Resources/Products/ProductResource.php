<?php

namespace App\Filament\Workshop\Resources\Products;

use App\Filament\Workshop\Resources\Products\Pages\CreateProduct;
use App\Filament\Workshop\Resources\Products\Pages\EditProduct;
use App\Filament\Workshop\Resources\Products\Pages\ListProducts;
use App\Filament\Workshop\Resources\Products\Schemas\ProductForm;
use App\Filament\Workshop\Resources\Products\Tables\ProductsTable;
use App\Models\Workshop\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    // protected static string|BackedEnum|null $navigationGroup = 'Inventory';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
