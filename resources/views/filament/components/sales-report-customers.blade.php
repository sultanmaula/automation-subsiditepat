@php
    $customers = is_array($customers ?? null) ? $customers : [];

    $formatCurrency = static function ($value) {
        return is_numeric($value)
            ? 'Rp ' . number_format((float) $value, 0, ',', '.')
            : '-';
    };
@endphp

<div class="space-y-4">
    <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm dark:border-white/10 dark:bg-gray-900">
        <div class="flex items-center gap-3">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                <x-heroicon-o-user-group class="h-5 w-5" />
            </div>
            <div>
                <h3 class="text-sm font-medium text-slate-900 dark:text-white">Daftar Konsumen</h3>
                <p class="text-xs text-slate-500 dark:text-gray-400">
                    Total {{ count($customers) }} konsumen
                </p>
            </div>
        </div>
    </div>

    @if (blank($customers))
        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 py-12 text-center dark:border-gray-700 dark:bg-gray-900/50">
            <div class="rounded-full bg-slate-100 p-3 dark:bg-gray-800">
                <x-heroicon-o-users class="h-8 w-8 text-slate-400 dark:text-gray-500" />
            </div>
            <h3 class="mt-4 text-sm font-medium text-slate-900 dark:text-white">Belum ada konsumen</h3>
            <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">Data konsumen tidak tersedia untuk periode ini</p>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($customers as $index => $customer)
                <div class="group relative flex flex-col justify-between rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-primary-500 hover:shadow-md dark:border-white/10 dark:bg-gray-900 dark:hover:border-primary-400">
                    <div>
                        <div class="flex items-start justify-between">
                            <div class="flex items-center gap-3">
                                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-600 dark:bg-gray-800 dark:text-gray-300">
                                    {{ $index + 1 }}
                                </span>
                                <div>
                                    <h4 class="text-sm font-semibold text-slate-900 dark:text-white">
                                        {{ $customer['name'] ?? 'Tanpa Nama' }}
                                    </h4>
                                    <p class="text-[10px] text-slate-500 dark:text-gray-400">
                                        ID: {{ \Illuminate\Support\Str::limit($customer['customer_report_id'] ?? '-', 12) }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 space-y-1">
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-500 dark:text-gray-400">NIK</span>
                                <span class="font-medium text-slate-700 dark:text-gray-300">{{ $customer['nationality_id'] ?? '-' }}</span>
                            </div>
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-500 dark:text-gray-400">Total Belanja</span>
                                <span class="font-bold text-primary-600 dark:text-primary-400">{{ $formatCurrency($customer['total'] ?? null) }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 border-t border-slate-100 pt-3 dark:border-gray-800">
                        <p class="mb-2 text-[10px] font-medium uppercase tracking-wider text-slate-400 dark:text-gray-500">Kategori</p>
                        <div class="flex flex-wrap gap-1.5">
                            @forelse ($customer['categories'] ?? [] as $category)
                                <span class="inline-flex items-center rounded-md bg-slate-50 px-2 py-1 text-[10px] font-medium text-slate-600 ring-1 ring-inset ring-slate-500/10 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700">
                                    {{ $category }}
                                </span>
                            @empty
                                <span class="text-[10px] italic text-slate-400">-</span>
                            @endforelse
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
