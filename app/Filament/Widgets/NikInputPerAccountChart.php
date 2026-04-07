<?php

namespace App\Filament\Widgets;

use App\Models\Account;
use App\Models\NikInputHistory;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class NikInputPerAccountChart extends ChartWidget
{
    protected ?string $heading = 'Input Akun';

    protected int|string|array $columnSpan = 1;

    protected static ?int $sort = 2;

    protected bool $isCollapsible = true;

    protected function getData(): array
    {
        $currentMonth = now()->format('Y-m');

        $histories = NikInputHistory::selectRaw('account_id, COUNT(*) as total')
            ->where('input_month', $currentMonth)
            ->where('is_failed', false)
            ->groupBy('account_id')
            ->get();

        $accounts = Account::whereIn('id', $histories->pluck('account_id'))->pluck('email', 'id');

        $colors = [
            '#3b82f6', '#ef4444', '#10b981', '#f59e0b',
            '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16',
            '#f97316', '#6366f1',
        ];

        $labels = [];
        $data = [];
        $backgroundColors = [];

        foreach ($histories as $index => $row) {
            $labels[] = $accounts->get($row->account_id, "Akun #{$row->account_id}");
            $data[] = (int) $row->total;
            $backgroundColors[] = $colors[$index % count($colors)] . '99';
        }

        return [
            'datasets' => [
                [
                    'label'           => Carbon::now()->translatedFormat('F Y'),
                    'data'            => $data,
                    'backgroundColor' => $backgroundColors,
                    'borderColor'     => array_map(fn($c) => substr($c, 0, 7), $backgroundColors),
                    'borderWidth'     => 2,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
