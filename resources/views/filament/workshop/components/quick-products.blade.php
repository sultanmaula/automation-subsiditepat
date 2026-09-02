@php
    $hasProducts = $quickProducts->isNotEmpty();
@endphp

@if($hasProducts)
<style>
    .qp-panel {
        margin-bottom: 1.5rem;
        border-radius: 0.75rem;
        border: 1px solid #e5e7eb;
        background: #ffffff;
        box-shadow: 0 1px 3px 0 rgb(0 0 0 / .07);
        overflow: hidden;
    }

    .dark .qp-panel {
        border-color: #374151;
        background: #1f2937;
    }

    .qp-header {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.75rem 1rem;
        border-bottom: 1px solid #e5e7eb;
        font-size: 0.875rem;
        font-weight: 600;
        color: #374151;
    }

    .dark .qp-header {
        border-color: #374151;
        color: #d1d5db;
    }

    .qp-header-icon {
        width: 1rem;
        height: 1rem;
        color: #9333ea;
    }

    .qp-cats {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        padding: 0.625rem 1rem;
        border-bottom: 1px solid #f3f4f6;
    }

    .dark .qp-cats {
        border-color: #374151;
    }

    .qp-cat-btn {
        padding: 0.25rem 0.875rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 500;
        border: none;
        cursor: pointer;
        transition: background 0.15s, color 0.15s;
        background: #f3f4f6;
        color: #374151;
    }

    .qp-cat-btn:hover {
        background: #e5e7eb;
    }

    .qp-cat-btn.active {
        background: #9333ea;
        color: #ffffff;
    }

    .dark .qp-cat-btn {
        background: #374151;
        color: #d1d5db;
    }

    .dark .qp-cat-btn:hover {
        background: #4b5563;
    }

    .qp-products {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        padding: 0.875rem 1rem;
    }

    .qp-prod-btn {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 2px;
        min-width: 96px;
        padding: 0.625rem 1rem;
        border-radius: 0.5rem;
        border: 1px solid #d8b4fe;
        background: #faf5ff;
        cursor: pointer;
        transition: background 0.15s, border-color 0.15s, transform 0.1s;
        text-align: center;
    }

    .qp-prod-btn:hover {
        background: #f3e8ff;
        border-color: #a855f7;
    }

    .qp-prod-btn:active {
        transform: scale(0.95);
    }

    .qp-prod-btn:disabled {
        opacity: 0.4;
        cursor: not-allowed;
    }

    .dark .qp-prod-btn {
        border-color: #6b21a8;
        background: #2e1065;
    }

    .dark .qp-prod-btn:hover {
        background: #3b0764;
        border-color: #7e22ce;
    }

    .qp-prod-name {
        font-size: 0.8125rem;
        font-weight: 600;
        line-height: 1.2;
        color: #7e22ce;
    }

    .dark .qp-prod-name {
        color: #d8b4fe;
    }

    .qp-prod-stock {
        font-size: 0.6875rem;
        color: #a855f7;
    }

    .dark .qp-prod-stock {
        color: #c084fc;
    }

    .qp-prod-location {
        font-size: 0.625rem;
        color: #92400e;
        background: #fef3c7;
        border-radius: 4px;
        padding: 1px 5px;
        margin-top: 2px;
    }

    .dark .qp-prod-location {
        color: #fde68a;
        background: #451a03;
    }

    .qp-prod-compat {
        font-size: 0.625rem;
        color: #1e40af;
        background: #dbeafe;
        border-radius: 4px;
        padding: 1px 5px;
        margin-top: 2px;
        line-height: 1.4;
        text-align: center;
    }

    .dark .qp-prod-compat {
        color: #bfdbfe;
        background: #1e3a5f;
    }

    .qp-empty {
        width: 100%;
        padding: 1rem;
        text-align: center;
        font-size: 0.875rem;
        color: #9ca3af;
    }
</style>

<div
    x-data="{
        selectedCategory: null,
        loading: false,
        products: @js($quickProducts->map(fn($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'sale_price' => (float) $p->sale_price,
            'stock' => $p->stock,
            'category_id' => $p->category_id,
            'location' => $p->location,
            'compatible_models' => $p->compatible_models ?? [],
        ])),
        categories: @js($quickCategories->map(fn($c) => ['id' => $c->id, 'name' => $c->name])),
        get filteredProducts() {
            if (this.selectedCategory === null) return this.products;
            return this.products.filter(p => p.category_id === this.selectedCategory);
        },
        async addProduct(productId) {
            if (this.loading) return;
            this.loading = true;
            try {
                await $wire.call('addQuickProduct', productId);
            } finally {
                this.loading = false;
            }
        }
    }"
    class="qp-panel"
>
    {{-- Header --}}
    <div class="qp-header">
        <svg class="qp-header-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
            <path fill-rule="evenodd" d="M14.615 1.595a.75.75 0 0 1 .359.852L12.982 9.75h7.268a.75.75 0 0 1 .548 1.262l-10.5 11.25a.75.75 0 0 1-1.272-.71l1.992-7.302H3.818a.75.75 0 0 1-.548-1.262l10.5-11.25a.75.75 0 0 1 .845-.143Z" clip-rule="evenodd"/>
        </svg>
        <span>Quick Products</span>
    </div>

    {{-- Category Filter --}}
    @if($quickCategories->isNotEmpty())
    <div class="qp-cats">
        <button
            type="button"
            class="qp-cat-btn"
            :class="selectedCategory === null ? 'active' : ''"
            @click="selectedCategory = null"
        >
            Semua
        </button>
        <template x-for="cat in categories" :key="cat.id">
            <button
                type="button"
                class="qp-cat-btn"
                :class="selectedCategory === cat.id ? 'active' : ''"
                @click="selectedCategory = cat.id"
                x-text="cat.name"
            ></button>
        </template>
    </div>
    @endif

    {{-- Product Buttons --}}
    <div class="qp-products">
        <template x-for="product in filteredProducts" :key="product.id">
            <button
                type="button"
                class="qp-prod-btn"
                :disabled="loading || product.stock <= 0"
                :title="'Rp ' + product.sale_price.toLocaleString('id-ID') + ' | Stok: ' + product.stock"
                :style="product.stock <= 0 ? 'opacity:0.4;cursor:not-allowed' : ''"
                @click="addProduct(product.id)"
            >
                <span class="qp-prod-name" x-text="product.name"></span>
                <span class="qp-prod-stock" x-text="'Stok: ' + product.stock"></span>
                <span class="qp-prod-location" x-show="product.location" x-text="'📍 ' + product.location"></span>
                <span class="qp-prod-compat" x-show="product.compatible_models && product.compatible_models.length > 0" x-text="'🔧 ' + product.compatible_models.join(' · ')"></span>
            </button>
        </template>

        <template x-if="filteredProducts.length === 0">
            <p class="qp-empty">Tidak ada produk di kategori ini.</p>
        </template>
    </div>
</div>
@endif
