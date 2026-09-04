<?php

namespace App\Filament\Workshop\Pages;

use App\Models\Workshop\Sale;
use App\Models\Workshop\SaleItem;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

class SalesReport extends Page
{
    protected string $view = 'filament.workshop.pages.sales-report';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Laporan Penjualan';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('reports') ?? false;
    }

    protected static ?string $title = 'Laporan Penjualan';

    protected static string | \UnitEnum | null $navigationGroup = 'Laporan';

    protected static ?int $navigationSort = 1;

    #[Url(as: 'from')]
    public string $dateFrom = '';

    #[Url(as: 'to')]
    public string $dateTo = '';

    public function mount(): void
    {
        if (! $this->dateFrom) {
            $this->dateFrom = now()->startOfMonth()->toDateString();
        }
        if (! $this->dateTo) {
            $this->dateTo = now()->toDateString();
        }
    }

    #[Computed]
    public function summary(): array
    {
        $from = $this->dateFrom . ' 00:00:00';
        $to   = $this->dateTo   . ' 23:59:59';

        $transactions = Sale::counted()->whereBetween('created_at', [$from, $to])->count();
        $revenue      = Sale::counted()->whereBetween('created_at', [$from, $to])->sum('total');

        $profit = SaleItem::query()
            ->whereHas('sale', fn ($q) => $q->counted()->whereBetween('created_at', [$from, $to]))
            ->join('workshop_products', 'workshop_sale_items.product_id', '=', 'workshop_products.id')
            ->selectRaw('SUM((workshop_sale_items.unit_price - workshop_products.cost_price) * workshop_sale_items.quantity) as profit')
            ->value('profit') ?? 0;

        return [
            'transactions' => $transactions,
            'revenue'      => (float) $revenue,
            'profit'       => (float) $profit,
        ];
    }

    #[Computed]
    public function dailySales(): Collection
    {
        return Sale::query()
            ->counted()
            ->whereBetween('created_at', [
                $this->dateFrom . ' 00:00:00',
                $this->dateTo   . ' 23:59:59',
            ])
            ->selectRaw("DATE(created_at) as date, COUNT(*) as count, SUM(total) as revenue")
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get();
    }

    #[Computed]
    public function topProducts(): Collection
    {
        return SaleItem::query()
            ->whereHas('sale', fn ($q) => $q->counted()->whereBetween('created_at', [
                $this->dateFrom . ' 00:00:00',
                $this->dateTo   . ' 23:59:59',
            ]))
            ->with('product:id,name')
            ->selectRaw('product_id, SUM(quantity) as total_qty, SUM(subtotal) as total_revenue')
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->get();
    }
}
