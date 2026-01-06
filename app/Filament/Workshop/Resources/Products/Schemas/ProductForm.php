<?php

namespace App\Filament\Workshop\Resources\Products\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Produk')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama Produk')
                            ->required()
                            ->maxLength(255),
                        Select::make('category_id')
                            ->label('Kategori')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload(),
                        TextInput::make('sku')
                            ->label('SKU/Kode')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(50),
                        TextInput::make('barcode')
                            ->label('Barcode')
                            ->unique(ignoreRecord: true)
                            ->maxLength(100)
                            ->extraInputAttributes([
                                'data-barcode-input' => true,
                                'data-state-path' => 'data.barcode',
                            ]),
                        ViewField::make('barcode_scanner')
                            ->label('')
                            ->dehydrated(false)
                            ->view('filament.workshop.components.barcode-scanner')
                            ->viewData([
                                'statePath' => 'data.barcode',
                            ]),
                        TextInput::make('unit')
                            ->label('Satuan')
                            ->default('pcs')
                            ->required()
                            ->maxLength(20),
                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true),
                        TextInput::make('cost_price')
                            ->label('Harga Modal')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                        TextInput::make('sale_price')
                            ->label('Harga Jual')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                        TextInput::make('stock')
                            ->label('Stok Awal')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                        TextInput::make('min_stock')
                            ->label('Stok Minimum')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                        Textarea::make('description')
                            ->label('Catatan')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
