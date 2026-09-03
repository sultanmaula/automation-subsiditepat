<x-filament-panels::page>
<style>
    .sr-filter {
        display: flex;
        align-items: flex-end;
        gap: 1rem;
        padding: 1rem;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 0.75rem;
        margin-bottom: 1.5rem;
    }
    .dark .sr-filter { background: #1f2937; border-color: #374151; }

    .sr-filter-group { display: flex; flex-direction: column; gap: 0.25rem; }
    .sr-filter-label { font-size: 0.75rem; font-weight: 600; color: #6b7280; }
    .dark .sr-filter-label { color: #9ca3af; }

    .sr-filter-input {
        padding: 0.5rem 0.75rem;
        border: 1px solid #d1d5db;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        color: #111827;
        background: #f9fafb;
        outline: none;
    }
    .sr-filter-input:focus { border-color: #9333ea; box-shadow: 0 0 0 2px rgb(147 51 234/.15); }
    .dark .sr-filter-input { background: #374151; border-color: #4b5563; color: #f9fafb; }

    .sr-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .sr-stat-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 0.75rem;
        padding: 1.25rem 1.5rem;
    }
    .dark .sr-stat-card { background: #1f2937; border-color: #374151; }

    .sr-stat-label {
        font-size: 0.75rem;
        font-weight: 600;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 0.5rem;
    }
    .dark .sr-stat-label { color: #9ca3af; }

    .sr-stat-value {
        font-size: 1.75rem;
        font-weight: 700;
        color: #111827;
        line-height: 1;
    }
    .dark .sr-stat-value { color: #f9fafb; }

    .sr-stat-value.green { color: #16a34a; }
    .sr-stat-value.purple { color: #9333ea; }

    .sr-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }

    .sr-table-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 0.75rem;
        overflow: hidden;
    }
    .dark .sr-table-card { background: #1f2937; border-color: #374151; }

    .sr-table-header {
        padding: 0.875rem 1rem;
        font-size: 0.875rem;
        font-weight: 600;
        color: #374151;
        border-bottom: 1px solid #e5e7eb;
    }
    .dark .sr-table-header { color: #d1d5db; border-color: #374151; }

    .sr-table { width: 100%; border-collapse: collapse; }
    .sr-table th {
        padding: 0.625rem 1rem;
        text-align: left;
        font-size: 0.6875rem;
        font-weight: 600;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        background: #f9fafb;
        border-bottom: 1px solid #f3f4f6;
    }
    .dark .sr-table th { background: #111827; color: #6b7280; border-color: #374151; }

    .sr-table td {
        padding: 0.625rem 1rem;
        font-size: 0.8125rem;
        color: #374151;
        border-bottom: 1px solid #f9fafb;
    }
    .dark .sr-table td { color: #d1d5db; border-color: #1f2937; }
    .sr-table tr:last-child td { border-bottom: none; }

    .sr-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
    .sr-table td.green { color: #16a34a; font-weight: 600; }
    .sr-empty {
        padding: 2rem;
        text-align: center;
        color: #9ca3af;
        font-size: 0.875rem;
    }
    .sr-rank {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 1.25rem;
        height: 1.25rem;
        border-radius: 50%;
        background: #f3e8ff;
        color: #7e22ce;
        font-size: 0.6875rem;
        font-weight: 700;
        margin-right: 0.5rem;
    }
</style>

{{-- Filter --}}
<div class="sr-filter">
    <div class="sr-filter-group">
        <label class="sr-filter-label">Dari Tanggal</label>
        <input type="date" class="sr-filter-input" wire:model.live="dateFrom">
    </div>
    <div class="sr-filter-group">
        <label class="sr-filter-label">Sampai Tanggal</label>
        <input type="date" class="sr-filter-input" wire:model.live="dateTo">
    </div>
</div>

{{-- Summary Stats --}}
<div class="sr-stats">
    <div class="sr-stat-card">
        <div class="sr-stat-label">Total Transaksi</div>
        <div class="sr-stat-value purple">{{ number_format($this->summary['transactions']) }}</div>
    </div>
    <div class="sr-stat-card">
        <div class="sr-stat-label">Total Omzet</div>
        <div class="sr-stat-value">Rp {{ number_format($this->summary['revenue'], 0, ',', '.') }}</div>
    </div>
    <div class="sr-stat-card">
        <div class="sr-stat-label">Estimasi Laba</div>
        <div class="sr-stat-value green">Rp {{ number_format($this->summary['profit'], 0, ',', '.') }}</div>
    </div>
</div>

{{-- Tables --}}
<div class="sr-grid">

    {{-- Daily Breakdown --}}
    <div class="sr-table-card">
        <div class="sr-table-header">📅 Penjualan per Hari</div>
        @if($this->dailySales->isNotEmpty())
        <table class="sr-table">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th style="text-align:right">Trx</th>
                    <th style="text-align:right">Omzet</th>
                </tr>
            </thead>
            <tbody>
                @foreach($this->dailySales as $row)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($row->date)->translatedFormat('d M Y') }}</td>
                    <td class="num">{{ $row->count }}</td>
                    <td class="num green">Rp {{ number_format($row->revenue, 0, ',', '.') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="sr-empty">Tidak ada transaksi di periode ini</div>
        @endif
    </div>

    {{-- Top Products --}}
    <div class="sr-table-card">
        <div class="sr-table-header">🏆 Produk Terlaris</div>
        @if($this->topProducts->isNotEmpty())
        <table class="sr-table">
            <thead>
                <tr>
                    <th>Produk</th>
                    <th style="text-align:right">Qty</th>
                    <th style="text-align:right">Revenue</th>
                </tr>
            </thead>
            <tbody>
                @foreach($this->topProducts as $i => $item)
                <tr>
                    <td>
                        <span class="sr-rank">{{ $i + 1 }}</span>
                        {{ $item->product?->name ?? '—' }}
                    </td>
                    <td class="num">{{ number_format($item->total_qty) }}</td>
                    <td class="num green">Rp {{ number_format($item->total_revenue, 0, ',', '.') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="sr-empty">Belum ada data produk</div>
        @endif
    </div>

</div>
</x-filament-panels::page>
