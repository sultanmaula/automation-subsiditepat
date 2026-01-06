<?php

namespace App\Filament\Workshop\Resources\StockMovements\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StockMovementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Pergerakan Stok')
                    ->columns(2)
                    ->schema([
                        Select::make('product_id')
                            ->label('Produk')
                            ->relationship('product', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('type')
                            ->label('Tipe')
                            ->options([
                                'in' => 'Masuk (Restock)',
                                'out' => 'Keluar',
                                'adjust' => 'Penyesuaian',
                            ])
                            ->required(),
                        TextInput::make('quantity')
                            ->label('Jumlah')
                            ->numeric()
                            ->required(),
                        TextInput::make('unit_cost')
                            ->label('Harga Modal / Unit')
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('note')
                            ->label('Catatan')
                            ->columnSpanFull()
                            ->maxLength(255),
                    ]),
            ]);
    }
}
