@php
    $summary = is_array($summary ?? null) ? $summary : [];
    $dateRange = $dateRange ?? [];

    $formatNumber = static function ($value) {
        return is_numeric($value)
            ? number_format((float) $value, 0, ',', '.')
            : '-';
    };

    $formatCurrency = static function ($value) {
        return is_numeric($value)
            ? 'Rp ' . number_format((float) $value, 0, ',', '.')
            : '-';
    };

    $formatDate = static function ($value) {
        if (blank($value)) {
            return '-';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('d M Y');
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->format('d M Y');
        } catch (\Throwable $exception) {
            return (string) $value;
        }
    };

    $cards = [
        [
            'label' => 'Tabung Terjual',
            'value' => $formatNumber($summary['sold'] ?? null),
            'description' => 'Unit terjual',
            'icon' => 'heroicon-o-cube',
            'color' => 'text-emerald-600',
            'bg_color' => 'bg-emerald-50',
            'border_color' => 'border-emerald-100',
        ],
        [
            'label' => 'Omzet (Gross)',
            'value' => $formatCurrency($summary['gross'] ?? null),
            'description' => 'Total pendapatan',
            'icon' => 'heroicon-o-banknotes',
            'color' => 'text-blue-600',
            'bg_color' => 'bg-blue-50',
            'border_color' => 'border-blue-100',
        ],
        [
            'label' => 'Modal',
            'value' => $formatCurrency($summary['modal'] ?? null),
            'description' => 'Total pengeluaran',
            'icon' => 'heroicon-o-credit-card',
            'color' => 'text-amber-600',
            'bg_color' => 'bg-amber-50',
            'border_color' => 'border-amber-100',
        ],
        [
            'label' => 'Profit',
            'value' => $formatCurrency($summary['profit'] ?? null),
            'description' => 'Keuntungan bersih',
            'icon' => 'heroicon-o-currency-dollar',
            'color' => 'text-purple-600',
            'bg_color' => 'bg-purple-50',
            'border_color' => 'border-purple-100',
        ],
    ];

    $hasSummary = collect($cards)
        ->filter(fn ($card) => $card['value'] !== '-')
        ->isNotEmpty();
@endphp

<div class="space-y-4">
    <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm dark:border-white/10 dark:bg-gray-900">
        <div class="flex items-center gap-3">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                <x-heroicon-o-calendar class="h-5 w-5" />
            </div>
            <div>
                <h3 class="text-sm font-medium text-slate-900 dark:text-white">Periode Laporan</h3>
                <p class="text-xs text-slate-500 dark:text-gray-400">
                    {{ $formatDate($dateRange['start'] ?? null) }} — {{ $formatDate($dateRange['end'] ?? null) }}
                </p>
            </div>
        </div>
    </div>

    @if (! $hasSummary)
        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 py-12 text-center dark:border-gray-700 dark:bg-gray-900/50">
            <div class="rounded-full bg-slate-100 p-3 dark:bg-gray-800">
                <x-heroicon-o-chart-bar class="h-8 w-8 text-slate-400 dark:text-gray-500" />
            </div>
            <h3 class="mt-4 text-sm font-medium text-slate-900 dark:text-white">Belum ada data</h3>
            <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">Silakan pilih rentang tanggal dan klik "Tampilkan Rekap"</p>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($cards as $card)
                <div class="relative overflow-hidden rounded-xl border bg-white p-4 shadow-sm transition hover:shadow-md dark:border-white/10 dark:bg-gray-900 {{ $card['border_color'] }}">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-xs font-medium text-slate-500 dark:text-gray-400">{{ $card['label'] }}</p>
                            <p class="mt-2 text-xl font-bold text-slate-900 dark:text-white">{{ $card['value'] }}</p>
                        </div>
                        <div class="rounded-lg p-2 {{ $card['bg_color'] }} {{ $card['color'] }}">
                            @svg($card['icon'], ['class' => 'h-5 w-5'])
                        </div>
                    </div>
                    <div class="mt-4 flex items-center gap-1">
                        <span class="text-[10px] font-medium text-slate-400 dark:text-gray-500">{{ $card['description'] }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
