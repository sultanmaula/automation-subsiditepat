<?php

namespace App\Filament\Workshop\Resources\Products\Tables;

use App\Models\Workshop\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Produk')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('barcode')
                    ->label('Barcode')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('category.name')
                    ->label('Kategori')
                    ->placeholder('-'),
                TextColumn::make('sale_price')
                    ->label('Harga Jual')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('stock')
                    ->label('Stok')
                    ->sortable()
                    ->color(fn (Product $record): string => $record->stock <= $record->min_stock ? 'danger' : 'success'),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
