<?php

namespace App\Filament\Workshop\Resources\Sales\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\HtmlString;

class SaleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Transaksi')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('sale_number')
                            ->label('No. Transaksi'),
                        TextEntry::make('customer_name')
                            ->label('Nama Pelanggan')
                            ->default('-'),
                        TextEntry::make('payment_method')
                            ->label('Metode Bayar')
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'cash' => 'Tunai',
                                'transfer' => 'Transfer',
                                'debit' => 'Debit',
                                'ewallet' => 'E-Wallet',
                                default => $state,
                            }),
                        TextEntry::make('qris_issuer')
                            ->label('Provider QRIS')
                            ->default('-')
                            ->visible(fn ($record) => $record->payment_method === 'qris'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'paid' => 'success',
                                'pending' => 'warning',
                                'cancelled' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('total')
                            ->label('Total')
                            ->formatStateUsing(fn ($state): HtmlString => new HtmlString(
                                '<span style="font-size: 1.5rem; font-weight: bold; color: #16a34a;">Rp ' . number_format((float) $state, 0, ',', '.') . '</span>'
                            )),
                        TextEntry::make('paid')
                            ->label('Bayar')
                            ->formatStateUsing(fn ($state): HtmlString => new HtmlString(
                                '<span style="font-size: 1.5rem; font-weight: bold; color: #0ea5e9;">Rp ' . number_format((float) $state, 0, ',', '.') . '</span>'
                            )),
                        TextEntry::make('change')
                            ->label('Kembalian')
                            ->formatStateUsing(fn ($state): HtmlString => new HtmlString(
                                '<span style="font-size: 1.5rem; font-weight: bold; color: #dc2626;">Rp ' . number_format((float) $state, 0, ',', '.') . '</span>'
                            )),
                    ]),
                Section::make('Item')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->schema([
                                TextEntry::make('product.name')
                                    ->label('Produk'),
                                TextEntry::make('quantity')
                                    ->label('Qty'),
                                TextEntry::make('unit_price')
                                    ->label('Harga')
                                    ->formatStateUsing(fn ($state): string => 'Rp ' . number_format((float) $state, 0, ',', '.')),
                                TextEntry::make('subtotal')
                                    ->label('Subtotal')
                                    ->formatStateUsing(fn ($state): string => 'Rp ' . number_format((float) $state, 0, ',', '.')),
                            ])
                            ->columns(4),
                    ]),
            ]);
    }
}
