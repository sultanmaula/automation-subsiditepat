<?php

namespace App\Filament\Workshop\Widgets;

use App\Filament\Workshop\Resources\Sales\SaleResource;
use App\Models\Workshop\Sale;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentSalesWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected static ?string $heading = 'Transaksi Terakhir';

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $actions = [];

        if (auth()->user()?->hasPermission('sales')) {
            $actions[] = Action::make('new_sale')
                ->label('Transaksi Baru')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->url(SaleResource::getUrl('create'));
        }

        return $table
            ->query(Sale::latest()->limit(8))
            ->paginated(false)
            ->headerActions($actions)
            ->columns([
                TextColumn::make('sale_number')
                    ->label('No. Nota')
                    ->searchable(),
                TextColumn::make('customer_name')
                    ->label('Pelanggan')
                    ->placeholder('-'),
                TextColumn::make('total')
                    ->label('Total')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format((float) $state, 0, ',', '.')),
                TextColumn::make('payment_method')
                    ->label('Metode')
                    ->badge(),
                TextColumn::make('cashier.name')
                    ->label('Kasir')
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ]);
    }
}
