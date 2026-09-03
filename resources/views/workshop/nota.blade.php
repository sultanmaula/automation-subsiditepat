<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nota {{ $sale->sale_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 12px;
            color: #000;
            background: #f3f4f6;
            display: flex;
            justify-content: center;
            padding: 2rem;
        }

        .receipt {
            width: 300px;
            background: #fff;
            padding: 1rem;
            box-shadow: 0 2px 8px rgb(0 0 0 / .15);
        }

        .receipt-header {
            text-align: center;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px dashed #999;
        }

        .shop-name {
            font-size: 16px;
            font-weight: bold;
            letter-spacing: 1px;
        }

        .shop-sub {
            font-size: 10px;
            color: #555;
            margin-top: 2px;
        }

        .receipt-meta {
            font-size: 10px;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px dashed #999;
            line-height: 1.6;
        }

        .receipt-meta .row {
            display: flex;
            justify-content: space-between;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0.5rem;
        }

        .items-table th {
            font-size: 10px;
            text-align: left;
            padding: 2px 0;
            border-bottom: 1px solid #ccc;
        }

        .items-table th:last-child { text-align: right; }

        .items-table td {
            font-size: 11px;
            padding: 3px 0;
            vertical-align: top;
        }

        .items-table td:last-child { text-align: right; white-space: nowrap; }

        .item-name { font-weight: 600; }
        .item-detail { font-size: 10px; color: #555; }

        .divider { border: none; border-top: 1px dashed #999; margin: 0.5rem 0; }

        .totals { font-size: 11px; }
        .totals .row {
            display: flex;
            justify-content: space-between;
            padding: 2px 0;
        }
        .totals .row.total {
            font-size: 13px;
            font-weight: bold;
            border-top: 1px solid #000;
            margin-top: 4px;
            padding-top: 4px;
        }

        /* QRIS section */
        .qris-section {
            text-align: center;
            margin: 0.75rem 0;
            padding: 0.75rem;
            border: 1px dashed #9333ea;
            border-radius: 6px;
        }

        .qris-label {
            font-size: 11px;
            font-weight: bold;
            color: #9333ea;
            margin-bottom: 0.5rem;
            letter-spacing: 1px;
        }

        .qris-img { width: 180px; height: 180px; }


        .receipt-footer {
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px dashed #999;
            text-align: center;
            font-size: 10px;
            color: #555;
            line-height: 1.6;
        }

        .no-print {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .btn-print, .btn-close {
            padding: 0.5rem 1.25rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
        }
        .btn-print { background: #9333ea; color: #fff; }
        .btn-print:hover { background: #7e22ce; }
        .btn-close { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
        .btn-close:hover { background: #e5e7eb; }

        @media print {
            body { background: #fff; padding: 0; }
            .receipt { box-shadow: none; width: 100%; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

<div style="width:100%;max-width:340px">
    <div class="no-print">
        <button class="btn-close" onclick="window.close()">✕ Tutup</button>
        <button class="btn-print" onclick="window.print()">🖨️ Cetak</button>
    </div>

    <div class="receipt">

        {{-- Header --}}
        <div class="receipt-header">
            <div class="shop-name">JAMUS MOTOR</div>
            <div class="shop-sub">Bengkel & Toko Sparepart</div>
        </div>

        {{-- Meta --}}
        <div class="receipt-meta">
            <div class="row"><span>No. Nota</span><span>{{ $sale->sale_number }}</span></div>
            <div class="row"><span>Tanggal</span><span>{{ $sale->created_at->format('d/m/Y H:i') }}</span></div>
            @if($sale->customer_name)
            <div class="row"><span>Pelanggan</span><span>{{ $sale->customer_name }}</span></div>
            @endif
            @if($sale->cashier)
            <div class="row"><span>Kasir</span><span>{{ $sale->cashier->name }}</span></div>
            @endif
        </div>

        {{-- Items --}}
        <table class="items-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sale->items as $item)
                <tr>
                    <td>
                        <div class="item-name">{{ $item->product?->name ?? '—' }}</div>
                        <div class="item-detail">{{ $item->quantity }} × Rp {{ number_format($item->unit_price, 0, ',', '.') }}</div>
                    </td>
                    <td>Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <hr class="divider">

        {{-- Totals --}}
        <div class="totals">
            <div class="row total">
                <span>TOTAL</span>
                <span>Rp {{ number_format($sale->total, 0, ',', '.') }}</span>
            </div>
            @if($sale->payment_method !== 'qris')
            <div class="row">
                <span>Bayar ({{ strtoupper($sale->payment_method) }})</span>
                <span>Rp {{ number_format($sale->paid, 0, ',', '.') }}</span>
            </div>
            <div class="row">
                <span>Kembalian</span>
                <span>Rp {{ number_format($sale->change, 0, ',', '.') }}</span>
            </div>
            @else
            <div class="row">
                <span>Metode</span><span>QRIS</span>
            </div>
            @endif
        </div>

        {{-- QRIS Section --}}
        @if($sale->payment_method === 'qris')
        <div class="qris-section" id="qris-section">
            <div class="qris-label" id="qris-label">
                @if($sale->payment_status === 'settlement') ✓ LUNAS @else ⚡ SCAN UNTUK BAYAR @endif
            </div>
            @if($sale->qris_qr_url)
            <div style="position:relative;display:inline-block;">
                <img class="qris-img" id="qris-img" src="{{ $sale->qris_qr_url }}" alt="QRIS"
                    @if($sale->payment_status === 'settlement') style="filter:blur(4px) grayscale(1);opacity:0.4;" @endif>
                <div id="qris-lunas-overlay" style="position:absolute;inset:0;display:{{ $sale->payment_status === 'settlement' ? 'flex' : 'none' }};align-items:center;justify-content:center;">
                    <span style="background:#16a34a;color:#fff;font-size:14px;font-weight:bold;padding:6px 14px;border-radius:6px;letter-spacing:1px;transform:rotate(-15deg);display:inline-block;">LUNAS</span>
                </div>
            </div>
            @else
            <div style="font-size:10px;color:#999">QR code tidak tersedia</div>
            @endif
        </div>
        @endif

        {{-- Footer --}}
        <div class="receipt-footer">
            <div>— Terima kasih atas kunjungan Anda —</div>
            <div>Barang yang sudah dibeli tidak dapat ditukar.</div>
        </div>

    </div>
</div>

<script>
@if($sale->payment_method !== 'qris')
    // Auto print untuk metode non-QRIS
    if (new URLSearchParams(window.location.search).get('auto') === '1') {
        window.onload = () => setTimeout(() => window.print(), 400);
        window.onafterprint = () => window.close();
    }
@endif
</script>
</body>
</html>
