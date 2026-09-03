<?php

namespace App\Filament\Workshop\Widgets;

use App\Models\Workshop\Product;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LowStockWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected static ?string $heading = 'Stok Hampir Habis';

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Product::where('is_active', true)
                    ->whereColumn('stock', '<=', 'min_stock')
                    ->orderBy('stock')
            )
            ->paginated(false)
            ->emptyStateHeading('Semua stok aman')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->columns([
                TextColumn::make('name')
                    ->label('Produk')
                    ->searchable(),
                TextColumn::make('category.name')
                    ->label('Kategori')
                    ->placeholder('-'),
                TextColumn::make('stock')
                    ->label('Stok Sekarang')
                    ->color('danger')
                    ->weight('bold'),
                TextColumn::make('min_stock')
                    ->label('Min. Stok'),
                TextColumn::make('location')
                    ->label('Lokasi')
                    ->placeholder('-')
                    ->icon('heroicon-o-map-pin')
                    ->iconColor('warning'),
            ]);
    }
}
