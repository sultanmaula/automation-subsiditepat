<?php

namespace App\Filament\Workshop\Widgets;

use App\Models\Workshop\Product;
use App\Models\Workshop\Sale;
use App\Models\Workshop\SaleItem;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class WorkshopStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $from = now()->startOfDay();
        $to   = now()->endOfDay();

        $todaySales   = Sale::whereBetween('created_at', [$from, $to])->count();
        $todayRevenue = Sale::whereBetween('created_at', [$from, $to])->sum('total');

        $todayProfit = SaleItem::query()
            ->whereHas('sale', fn ($q) => $q->whereBetween('created_at', [$from, $to]))
            ->join('workshop_products', 'workshop_sale_items.product_id', '=', 'workshop_products.id')
            ->selectRaw('SUM((workshop_sale_items.unit_price - workshop_products.cost_price) * workshop_sale_items.quantity) as profit')
            ->value('profit') ?? 0;

        $lowStock = Product::where('is_active', true)
            ->whereColumn('stock', '<=', 'min_stock')
            ->count();

        return [
            Stat::make('Transaksi Hari Ini', $todaySales)
                ->icon('heroicon-o-shopping-cart')
                ->color('primary'),

            Stat::make('Omzet Hari Ini', 'Rp ' . number_format((float) $todayRevenue, 0, ',', '.'))
                ->icon('heroicon-o-banknotes')
                ->color('success'),

            Stat::make('Laba Hari Ini', 'Rp ' . number_format((float) $todayProfit, 0, ',', '.'))
                ->icon('heroicon-o-chart-bar')
                ->color('success'),

            Stat::make('Stok Hampir Habis', $lowStock . ' produk')
                ->icon('heroicon-o-exclamation-triangle')
                ->color($lowStock > 0 ? 'danger' : 'success'),
        ];
    }
}
