<x-filament-panels::page>
<style>
    .so-wrap { display: grid; grid-template-columns: 380px 1fr; gap: 1.5rem; align-items: start; }

    .so-form-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 0.75rem;
        overflow: hidden;
    }
    .dark .so-form-card { background: #1f2937; border-color: #374151; }

    .so-form-header {
        padding: 0.875rem 1rem;
        font-size: 0.875rem;
        font-weight: 600;
        color: #374151;
        border-bottom: 1px solid #e5e7eb;
    }
    .dark .so-form-header { color: #d1d5db; border-color: #374151; }

    .so-form-body { padding: 1rem; display: flex; flex-direction: column; gap: 0.875rem; }

    .so-field { display: flex; flex-direction: column; gap: 0.25rem; }
    .so-label { font-size: 0.75rem; font-weight: 600; color: #6b7280; }
    .dark .so-label { color: #9ca3af; }

    .so-input, .so-select, .so-textarea {
        padding: 0.625rem 0.75rem;
        border: 1px solid #d1d5db;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        color: #111827;
        background: #f9fafb;
        outline: none;
        width: 100%;
        box-sizing: border-box;
    }
    .so-input:focus, .so-select:focus, .so-textarea:focus {
        border-color: #9333ea;
        box-shadow: 0 0 0 2px rgb(147 51 234 / .15);
    }
    .dark .so-input, .dark .so-select, .dark .so-textarea {
        background: #374151; border-color: #4b5563; color: #f9fafb;
    }

    .so-stock-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        padding: 0.375rem 0.75rem;
        border-radius: 0.5rem;
        background: #f3e8ff;
        border: 1px solid #d8b4fe;
        font-size: 0.8125rem;
        font-weight: 600;
        color: #7e22ce;
        margin-top: 0.25rem;
    }
    .dark .so-stock-badge { background: #2e1065; border-color: #6b21a8; color: #d8b4fe; }

    .so-btn {
        padding: 0.625rem 1.25rem;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        background: #9333ea;
        color: #fff;
        width: 100%;
        transition: background 0.15s;
    }
    .so-btn:hover { background: #7e22ce; }
    .so-btn:active { background: #6b21a8; }

    .so-log-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 0.75rem;
        overflow: hidden;
    }
    .dark .so-log-card { background: #1f2937; border-color: #374151; }

    .so-log-header {
        padding: 0.875rem 1rem;
        font-size: 0.875rem;
        font-weight: 600;
        color: #374151;
        border-bottom: 1px solid #e5e7eb;
    }
    .dark .so-log-header { color: #d1d5db; border-color: #374151; }

    .so-table { width: 100%; border-collapse: collapse; }
    .so-table th {
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
    .dark .so-table th { background: #111827; color: #6b7280; border-color: #374151; }
    .so-table td {
        padding: 0.625rem 1rem;
        font-size: 0.8125rem;
        color: #374151;
        border-bottom: 1px solid #f9fafb;
        vertical-align: top;
    }
    .dark .so-table td { color: #d1d5db; border-color: #1f2937; }
    .so-table tr:last-child td { border-bottom: none; }

    .so-delta-up { color: #16a34a; font-weight: 700; }
    .so-delta-down { color: #dc2626; font-weight: 700; }

    .so-empty { padding: 2rem; text-align: center; color: #9ca3af; font-size: 0.875rem; }
</style>

<div class="so-wrap">

    {{-- Form --}}
    <div class="so-form-card">
        <div class="so-form-header">📋 Koreksi Stok</div>
        <div class="so-form-body">

            <div class="so-field">
                <label class="so-label">Pilih Produk</label>
                <select class="so-select" wire:model.live="productId">
                    <option value="">— pilih produk —</option>
                    @foreach($this->products as $product)
                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                    @endforeach
                </select>
            </div>

            @if($this->selectedProduct)
            <div class="so-field">
                <label class="so-label">Stok di Sistem</label>
                <div class="so-stock-badge">
                    📦 {{ $this->selectedProduct->stock }} unit
                </div>
            </div>
            @endif

            <div class="so-field">
                <label class="so-label">Jumlah Stok Aktual (hasil hitung fisik)</label>
                <input
                    type="number"
                    class="so-input"
                    wire:model="actualCount"
                    placeholder="Masukkan jumlah stok yang ada..."
                    min="0"
                >
            </div>

            <div class="so-field">
                <label class="so-label">Keterangan (opsional)</label>
                <textarea
                    class="so-textarea"
                    wire:model="reason"
                    rows="2"
                    placeholder="cth: opname bulan September, koreksi setelah cek fisik..."
                ></textarea>
            </div>

            <button type="button" class="so-btn" wire:click="adjustStock" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="adjustStock">Simpan Koreksi</span>
                <span wire:loading wire:target="adjustStock">Menyimpan...</span>
            </button>

        </div>
    </div>

    {{-- Log --}}
    <div class="so-log-card">
        <div class="so-log-header">🕐 Riwayat Koreksi Stok</div>
        @if($this->recentAdjustments->isNotEmpty())
        <table class="so-table">
            <thead>
                <tr>
                    <th>Produk</th>
                    <th>Selisih</th>
                    <th>Keterangan</th>
                    <th>Oleh</th>
                    <th>Waktu</th>
                </tr>
            </thead>
            <tbody>
                @foreach($this->recentAdjustments as $adj)
                <tr>
                    <td>{{ $adj->product?->name ?? '—' }}</td>
                    <td>
                        @if($adj->quantity > 0)
                            <span class="so-delta-up">+{{ $adj->quantity }}</span>
                        @else
                            <span class="so-delta-down">{{ $adj->quantity }}</span>
                        @endif
                    </td>
                    <td style="color:#6b7280">{{ $adj->note ?? '-' }}</td>
                    <td style="color:#6b7280">{{ $adj->creator?->name ?? '-' }}</td>
                    <td style="color:#6b7280;white-space:nowrap">
                        {{ $adj->created_at->format('d/m/y H:i') }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div class="so-empty">Belum ada riwayat koreksi stok</div>
        @endif
    </div>

</div>
</x-filament-panels::page>
