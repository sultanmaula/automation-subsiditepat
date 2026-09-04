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

        .shop-addr {
            font-size: 9px;
            color: #555;
            margin-top: 4px;
            line-height: 1.45;
        }

        .shop-hours {
            font-size: 9px;
            font-weight: bold;
            color: #000;
            margin-top: 3px;
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

        /* Cap status pembayaran */
        .stamp {
            text-align: center;
            margin: 0.75rem 0 0.25rem;
            padding: 0.4rem;
            font-size: 13px;
            font-weight: bold;
            letter-spacing: 2px;
            border: 2px solid #000;
            border-radius: 4px;
        }
        .stamp.unpaid { border-style: dashed; }

        .qris-ref {
            text-align: center;
            font-size: 9px;
            color: #555;
            word-break: break-all;
        }

        /* Panel tunggu — tidak ikut tercetak */
        .waiting {
            text-align: center;
            padding: 1rem;
            margin-bottom: 1rem;
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: 0.5rem;
            font-size: 0.8rem;
            color: #92400e;
            line-height: 1.6;
        }
        .waiting strong { display: block; font-size: 0.95rem; margin-bottom: 0.25rem; }


        /* Blok ajakan ulasan Google Maps */
        .review {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 0.75rem;
            padding-top: 0.6rem;
            border-top: 1px dashed #999;
        }

        .review-qr { flex: 0 0 auto; line-height: 0; }
        /* 88px ~= 23mm saat dicetak. QR versi 5 (37 modul) jatuh di ~0,62mm
           per modul — batas bawah yang masih aman dibaca kamera HP dari
           kertas thermal. Jangan dikecilkan lagi. */
        .review-qr svg { display: block; width: 88px; height: 88px; }

        .review-text { font-size: 9px; line-height: 1.5; }
        .review-stars { font-size: 12px; letter-spacing: 2px; }
        .review-lead { font-weight: bold; font-size: 10px; }

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

    @if($sale->payment_method === 'qris' && $sale->payment_status !== 'settlement')
    <div class="no-print waiting">
        <strong id="wait-title">⏳ Menunggu pembayaran…</strong>
        <span id="wait-note">Nota akan tercetak sendiri begitu pembayaran masuk.</span>
    </div>
    @endif

    <div class="receipt">

        {{-- Header --}}
        <div class="receipt-header">
            <div class="shop-name">JAMUS MOTOR</div>
            <div class="shop-sub">Bengkel & Toko Sparepart</div>
            <div class="shop-addr">
                Jl. Raya Menganto, RT.10/RW.03, Menganto<br>
                Kec. Mojowarno, Kabupaten Jombang<br>
                Jawa Timur 61475
            </div>
            <div class="shop-hours">Buka 07.00 - 16.00 WIB</div>
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
            @if($sale->qris_issuer)
            <div class="row">
                <span>Provider</span><span>{{ $sale->qris_issuer }}</span>
            </div>
            @endif
            @endif
        </div>

        {{-- Cap status pembayaran, berlaku untuk semua metode. Khusus QRIS, QR
             sengaja tidak ikut dicetak: nota adalah bukti transaksi selesai,
             sedangkan QR ditampilkan di layar HP pegawai. --}}
        @php
            $isPaid  = in_array($sale->payment_status, ['paid', 'settlement'], true);
            $metode  = $sale->payment_method === 'qris' ? 'QRIS' : 'TUNAI';
        @endphp

        @if($isPaid)
        <div class="stamp paid">✓ LUNAS — {{ $metode }}</div>
        @else
        <div class="stamp unpaid">BELUM LUNAS</div>
        @endif

        @if($sale->payment_method === 'qris' && $sale->qris_transaction_id)
        <div class="qris-ref">Ref: {{ $sale->qris_transaction_id }}</div>
        @endif

        {{-- Ajakan ulasan. QR dirender sebagai SVG di server, bukan menumpang
             layanan gambar pihak ketiga, supaya nota lama tetap utuh. --}}
        @if(filled(config('shop.maps_review_url')))
        <div class="review">
            <div class="review-qr">{!! App\Support\QrCode::svg(config('shop.maps_review_url'), 88) !!}</div>
            <div class="review-text">
                <div class="review-stars">★★★★★</div>
                <div class="review-lead">Puas dengan layanan kami?</div>
                <div>Scan untuk beri bintang 5 di Google Maps.</div>
                <div>Ulasan Anda sangat membantu kami.</div>
            </div>
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
    // Cetak otomatis berlaku untuk semua metode. Untuk QRIS, tab ini menunggu
    // dulu lalu memuat ulang dirinya dengan ?auto=1 setelah lunas, supaya yang
    // keluar dari printer sudah bercap LUNAS.
    if (new URLSearchParams(window.location.search).get('auto') === '1') {
        window.onload = () => setTimeout(() => window.print(), 400);
        window.onafterprint = () => window.close();
    }

@if($sale->payment_method === 'qris' && $sale->payment_status !== 'settlement')
    (function () {
        const url   = @json(route('workshop.sale.payment-status', $sale->id));
        const done  = @json(route('workshop.nota', $sale->id) . '?auto=1');
        const title = document.getElementById('wait-title');
        const note  = document.getElementById('wait-note');
        let attempts = 0;

        const timer = setInterval(async () => {
            if (++attempts > 320) {
                clearInterval(timer);
                title.textContent = '⌛ Berhenti memantau';
                note.textContent  = 'Muat ulang halaman ini untuk memeriksa lagi.';
                return;
            }
            try {
                const res  = await fetch(url);
                const data = await res.json();

                if (data.payment_status === 'settlement') {
                    clearInterval(timer);
                    title.textContent = '✓ Pembayaran diterima — mencetak…';
                    note.textContent  = '';
                    location.replace(done);
                } else if (data.payment_status === 'expired') {
                    clearInterval(timer);
                    title.textContent = '✕ QRIS kedaluwarsa';
                    note.textContent  = 'Gunakan tombol "Bayar Ulang" di daftar transaksi.';
                }
            } catch (e) {}
        }, 3000);
    })();
@endif
</script>
</body>
</html>
