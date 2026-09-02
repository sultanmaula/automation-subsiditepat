<x-filament-panels::page>
<style>
    .pf-wrap {
        max-width: 900px;
        margin: 0 auto;
    }

    .pf-search-box {
        position: relative;
        margin-bottom: 1.5rem;
    }

    .pf-search-icon {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        width: 1.25rem;
        height: 1.25rem;
        color: #9ca3af;
        pointer-events: none;
    }

    .pf-search-input {
        width: 100%;
        padding: 0.875rem 1rem 0.875rem 3rem;
        font-size: 1rem;
        border-radius: 0.75rem;
        border: 2px solid #e5e7eb;
        background: #ffffff;
        color: #111827;
        outline: none;
        transition: border-color 0.15s, box-shadow 0.15s;
        box-shadow: 0 1px 3px rgb(0 0 0 / .06);
    }

    .pf-search-input:focus {
        border-color: #9333ea;
        box-shadow: 0 0 0 3px rgb(147 51 234 / .15);
    }

    .dark .pf-search-input {
        background: #1f2937;
        border-color: #374151;
        color: #f9fafb;
    }

    .dark .pf-search-input:focus {
        border-color: #9333ea;
    }

    .pf-hint {
        text-align: center;
        color: #9ca3af;
        font-size: 0.875rem;
        padding: 3rem 0;
    }

    .pf-results {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 1rem;
    }

    .pf-card {
        border-radius: 0.75rem;
        border: 1px solid #e5e7eb;
        background: #ffffff;
        padding: 1rem;
        box-shadow: 0 1px 3px rgb(0 0 0 / .06);
        transition: box-shadow 0.15s, border-color 0.15s;
    }

    .pf-card:hover {
        border-color: #d8b4fe;
        box-shadow: 0 4px 12px rgb(147 51 234 / .1);
    }

    .dark .pf-card {
        background: #1f2937;
        border-color: #374151;
    }

    .dark .pf-card:hover {
        border-color: #7e22ce;
    }

    .pf-card-name {
        font-size: 1rem;
        font-weight: 700;
        color: #111827;
        margin-bottom: 0.25rem;
        line-height: 1.3;
    }

    .dark .pf-card-name {
        color: #f9fafb;
    }

    .pf-card-cat {
        font-size: 0.75rem;
        color: #6b7280;
        margin-bottom: 0.625rem;
    }

    .dark .pf-card-cat {
        color: #9ca3af;
    }

    .pf-card-location {
        display: flex;
        align-items: flex-start;
        gap: 0.375rem;
        padding: 0.5rem 0.625rem;
        border-radius: 0.5rem;
        background: #fef3c7;
        border: 1px solid #fde68a;
        margin-bottom: 0.5rem;
    }

    .dark .pf-card-location {
        background: #451a03;
        border-color: #78350f;
    }

    .pf-card-location-icon {
        flex-shrink: 0;
        width: 0.875rem;
        height: 0.875rem;
        color: #d97706;
        margin-top: 1px;
    }

    .dark .pf-card-location-icon {
        color: #fbbf24;
    }

    .pf-card-location-text {
        font-size: 0.8125rem;
        font-weight: 600;
        color: #92400e;
        line-height: 1.3;
    }

    .dark .pf-card-location-text {
        color: #fde68a;
    }

    .pf-card-no-location {
        font-size: 0.75rem;
        color: #9ca3af;
        font-style: italic;
        margin-bottom: 0.5rem;
    }

    .pf-compat-wrap {
        display: flex;
        flex-wrap: wrap;
        gap: 0.25rem;
        margin-bottom: 0.625rem;
    }

    .pf-compat-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.2rem;
        font-size: 0.6875rem;
        font-weight: 500;
        color: #1e40af;
        background: #dbeafe;
        border: 1px solid #bfdbfe;
        border-radius: 9999px;
        padding: 0.125rem 0.5rem;
    }

    .dark .pf-compat-badge {
        color: #bfdbfe;
        background: #1e3a5f;
        border-color: #1d4ed8;
    }

    .pf-card-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 0.5rem;
        padding-top: 0.5rem;
        border-top: 1px solid #f3f4f6;
    }

    .dark .pf-card-meta {
        border-color: #374151;
    }

    .pf-card-price {
        font-size: 0.875rem;
        font-weight: 600;
        color: #16a34a;
    }

    .pf-card-stock {
        font-size: 0.75rem;
        padding: 0.125rem 0.5rem;
        border-radius: 9999px;
        font-weight: 500;
    }

    .pf-card-stock.ok {
        background: #dcfce7;
        color: #166534;
    }

    .pf-card-stock.low {
        background: #fee2e2;
        color: #991b1b;
    }

    .dark .pf-card-stock.ok {
        background: #14532d;
        color: #86efac;
    }

    .dark .pf-card-stock.low {
        background: #7f1d1d;
        color: #fca5a5;
    }

    .pf-alt-section {
        margin-top: 0.625rem;
        padding-top: 0.625rem;
        border-top: 1px dashed #e5e7eb;
    }

    .dark .pf-alt-section {
        border-color: #374151;
    }

    .pf-alt-label {
        font-size: 0.6875rem;
        font-weight: 600;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 0.375rem;
    }

    .pf-alt-item {
        font-size: 0.75rem;
        color: #374151;
        padding: 0.25rem 0;
        display: flex;
        align-items: center;
        gap: 0.25rem;
    }

    .dark .pf-alt-item {
        color: #d1d5db;
    }

    .pf-alt-dot {
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: #9333ea;
        flex-shrink: 0;
    }

    .pf-no-results {
        text-align: center;
        color: #9ca3af;
        font-size: 0.875rem;
        padding: 3rem 0;
    }
</style>

<div class="pf-wrap">
    {{-- Search Box --}}
    <div class="pf-search-box">
        <svg class="pf-search-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
        </svg>
        <input
            type="text"
            class="pf-search-input"
            placeholder="Ketik nama barang, nama motor (misal: Jupiter Z), barcode, atau lokasi..."
            wire:model.live.debounce.300ms="search"
            autofocus
        >
    </div>

    {{-- Hint awal --}}
    @if(strlen(trim($search)) < 2)
        <p class="pf-hint">Ketik minimal 2 huruf untuk mencari barang atau nama motor</p>

    {{-- Hasil --}}
    @elseif($this->results->isNotEmpty())
        <div class="pf-results">
            @foreach($this->results as $product)
            @php
                $productAlts = collect();
                if (!empty($product->compatible_models) && $product->category_id) {
                    $productAlts = ($this->alternatives->get($product->category_id) ?? collect())
                        ->filter(fn($alt) => !empty(array_intersect($alt->compatible_models ?? [], $product->compatible_models ?? [])))
                        ->take(3);
                }
            @endphp
            <div class="pf-card">
                <div class="pf-card-name">{{ $product->name }}</div>
                <div class="pf-card-cat">{{ $product->category?->name ?? '—' }}</div>

                {{-- Lokasi --}}
                @if($product->location)
                    <div class="pf-card-location">
                        <svg class="pf-card-location-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                            <path fill-rule="evenodd" d="M11.54 22.351l.07.04.028.016a.76.76 0 0 0 .723 0l.028-.015.071-.041a16.975 16.975 0 0 0 1.144-.742 19.58 19.58 0 0 0 2.683-2.282c1.944-2.079 3.218-4.379 3.218-7.327C19.5 6.108 15.978 2.5 12 2.5c-3.978 0-7.5 3.608-7.5 8.001 0 2.948 1.274 5.248 3.218 7.327a19.585 19.585 0 0 0 2.682 2.282 16.975 16.975 0 0 0 1.144.742zM12 13.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6z" clip-rule="evenodd"/>
                        </svg>
                        <span class="pf-card-location-text">{{ $product->location }}</span>
                    </div>
                @else
                    <p class="pf-card-no-location">Lokasi belum diisi</p>
                @endif

                {{-- Kompatibel dengan motor --}}
                @if(!empty($product->compatible_models))
                    <div class="pf-compat-wrap">
                        @foreach($product->compatible_models as $motor)
                            <span class="pf-compat-badge">🔧 {{ $motor }}</span>
                        @endforeach
                    </div>
                @endif

                {{-- Meta --}}
                <div class="pf-card-meta">
                    <span class="pf-card-price">Rp {{ number_format($product->sale_price, 0, ',', '.') }}</span>
                    <span class="pf-card-stock {{ $product->stock <= $product->min_stock ? 'low' : 'ok' }}">
                        Stok: {{ $product->stock }}
                    </span>
                </div>

                {{-- Alternatif --}}
                @if($productAlts->isNotEmpty())
                    <div class="pf-alt-section">
                        <div class="pf-alt-label">Alternatif serupa</div>
                        @foreach($productAlts as $alt)
                            <div class="pf-alt-item">
                                <div class="pf-alt-dot"></div>
                                <span>
                                    {{ $alt->name }}
                                    @if($alt->location)
                                        <span style="color:#9ca3af"> — {{ $alt->location }}</span>
                                    @endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
            @endforeach
        </div>

    {{-- Tidak ditemukan --}}
    @else
        <p class="pf-no-results">Tidak ada barang yang cocok dengan "<strong>{{ $search }}</strong>"</p>
    @endif
</div>
</x-filament-panels::page>
