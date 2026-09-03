<?php

namespace App\Filament\Workshop\Resources\Sales\Schemas;

use App\Models\Workshop\Product;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class SaleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Transaksi')
                    ->columns(2)
                    ->schema([
                        TextInput::make('scan_barcode')
                            ->label('Scan Barcode')
                            ->placeholder('Scan atau ketik barcode, lalu tekan Enter')
                            ->dehydrated(false)
                            ->live(onBlur: true)
                            ->autofocus()
                            ->columnSpanFull()
                            ->extraInputAttributes([
                                'onkeydown' => "if(event.key === 'Enter') { event.preventDefault(); this.blur(); this.focus(); }",
                            ])
                            ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                $barcode = trim((string) $state);

                                if ($barcode === '') {
                                    return;
                                }

                                $product = Product::query()
                                    ->where('barcode', $barcode)
                                    ->first();

                                if (! $product) {
                                    Notification::make()
                                        ->title('Barcode tidak ditemukan')
                                        ->danger()
                                        ->send();
                                    $set('scan_barcode', null);
                                    return;
                                }

                                $items = $get('items') ?? [];
                                $updated = false;

                                foreach ($items as $index => $item) {
                                    if (($item['product_id'] ?? null) === $product->id) {
                                        $quantity = (int) ($item['quantity'] ?? 0) + 1;
                                        $unitPrice = (float) ($item['unit_price'] ?? $product->sale_price);
                                        $items[$index]['quantity'] = $quantity;
                                        $items[$index]['unit_price'] = $unitPrice;
                                        $items[$index]['subtotal'] = $quantity * $unitPrice;
                                        $updated = true;
                                        break;
                                    }
                                }

                                if (! $updated) {
                                    $items[] = [
                                        'product_id' => $product->id,
                                        'quantity' => 1,
                                        'unit_price' => (float) $product->sale_price,
                                        'subtotal' => (float) $product->sale_price,
                                    ];
                                }

                                $set('items', array_values($items));
                                $set('scan_barcode', null);
                            }),
                        TextInput::make('customer_name')
                            ->label('Nama Pelanggan')
                            ->maxLength(255),
                        Select::make('payment_method')
                            ->label('Metode Bayar')
                            ->options([
                                'cash' => 'Tunai',
                                'qris' => 'QRIS',
                            ])
                            ->default('cash')
                            ->live()
                            ->required(),
                        TextInput::make('paid')
                            ->label('Bayar')
                            ->prefix('Rp')
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->hidden(fn (Get $get): bool => $get('payment_method') === 'qris')
                            ->extraInputAttributes([
                                'x-on:input' => "
                                    let val = \$event.target.value.replace(/[^0-9]/g, '');
                                    \$event.target.value = val.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                                ",
                            ])
                            ->dehydrateStateUsing(fn ($state) => (int) preg_replace('/[^0-9]/', '', (string) $state)),
                        Placeholder::make('total_preview')
                            ->label('Total')
                            ->content(fn (Get $get): \Illuminate\Support\HtmlString => new \Illuminate\Support\HtmlString(
                                '<span style="font-size: 1.5rem; font-weight: bold; color: #16a34a;">' . self::formatCurrency(self::calculateTotal($get)) . '</span>'
                            ))
                            ->live(),
                        Placeholder::make('change_preview')
                            ->label('Kembalian')
                            ->content(fn (Get $get): \Illuminate\Support\HtmlString => new \Illuminate\Support\HtmlString(
                                '<span style="font-size: 1.5rem; font-weight: bold; color: #dc2626;">' . self::formatCurrency(self::calculateChange($get)) . '</span>'
                            ))
                            ->live()
                            ->hidden(fn (Get $get): bool => $get('payment_method') === 'qris'),
                    ]),
                Section::make('Item')
                    ->schema([
                        Repeater::make('items')
                            ->relationship()
                            ->schema([
                                Select::make('product_id')
                                    ->label('Produk')
                                    ->options(fn (): array => Product::query()
                                        ->orderBy('name')
                                        ->limit(50)
                                        ->get()
                                        ->mapWithKeys(fn (Product $product) => [$product->id => $product->label])
                                        ->toArray())
                                    ->searchable()
                                    ->preload()
                                    ->getSearchResultsUsing(function (string $search): array {
                                        return Product::query()
                                            ->where('name', 'like', "%{$search}%")
                                            ->orWhere('sku', 'like', "%{$search}%")
                                            ->orWhere('barcode', 'like', "%{$search}%")
                                            ->limit(50)
                                            ->get()
                                            ->mapWithKeys(fn (Product $product) => [$product->id => $product->label])
                                            ->toArray();
                                    })
                                    ->getOptionLabelUsing(fn ($value): ?string => Product::find($value)?->label)
                                    ->required()
                                    ->reactive()
                                    ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                        $product = Product::find($state);

                                        if (! $product) {
                                            return;
                                        }

                                        $set('unit_price', (float) $product->sale_price);

                                        $quantity = (int) ($get('quantity') ?: 1);
                                        $set('subtotal', $quantity * (float) $product->sale_price);
                                    }),
                                TextInput::make('quantity')
                                    ->label('Qty')
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(1)
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                        $unitPrice = (float) ($get('unit_price') ?: 0);
                                        $set('subtotal', (int) $state * $unitPrice);
                                    }),
                                TextInput::make('unit_price')
                                    ->label('Harga')
                                    ->numeric()
                                    ->minValue(0)
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                        $quantity = (int) ($get('quantity') ?: 1);
                                        $set('subtotal', $quantity * (float) $state);
                                    }),
                                TextInput::make('subtotal')
                                    ->label('Subtotal')
                                    ->numeric()
                                    ->disabled()
                                    ->dehydrated(),
                            ])
                            ->columns(4)
                            ->defaultItems(0)
                            ->addActionLabel('Tambah Item'),
                    ]),
            ]);
    }

    private static function calculateTotal(Get $get): float
    {
        $items = $get('items') ?? [];

        return collect($items)->sum(function (array $item): float {
            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);

            return $quantity * $unitPrice;
        });
    }

    private static function calculateChange(Get $get): float
    {
        $total = self::calculateTotal($get);
        $paidRaw = $get('paid') ?? 0;
        $paid = (float) preg_replace('/[^0-9]/', '', (string) $paidRaw);

        return max($paid - $total, 0);
    }

    private static function formatCurrency(float $value): string
    {
        return 'Rp ' . number_format($value, 0, ',', '.');
    }
}
