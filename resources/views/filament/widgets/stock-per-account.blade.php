<x-filament-widgets::widget>
    <x-filament::section collapsible>
        <x-slot name="heading">Stok LPG per Akun</x-slot>

        <div style="display:flex;justify-content:flex-end;margin-bottom:0.75rem;">
            <button
                wire:click="refreshStock"
                wire:loading.attr="disabled"
                style="display:inline-flex;align-items:center;gap:6px;border-radius:6px;padding:6px 12px;font-size:12px;font-weight:500;border:1px solid #fce7f3;background:#fdf2f8;color:#be185d;cursor:pointer;transition:background 0.15s;"
                onmouseover="this.style.background='#fce7f3'" onmouseout="this.style.background='#fdf2f8'"
            >
                <svg wire:loading.remove wire:target="refreshStock" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="14" height="14">
                    <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H3.989a.75.75 0 0 0-.75.75v4.242a.75.75 0 0 0 1.5 0v-2.43l.31.31a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39Zm1.23-3.723a.75.75 0 0 0 .219-.53V2.929a.75.75 0 0 0-1.5 0V5.36l-.31-.31A7 7 0 0 0 3.239 8.188a.75.75 0 1 0 1.448.389A5.5 5.5 0 0 1 13.89 6.11l.311.31h-2.432a.75.75 0 0 0 0 1.5h4.243a.75.75 0 0 0 .53-.219Z" clip-rule="evenodd" />
                </svg>
                <svg wire:loading wire:target="refreshStock" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="14" height="14" style="animation:spin 1s linear infinite;">
                    <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H3.989a.75.75 0 0 0-.75.75v4.242a.75.75 0 0 0 1.5 0v-2.43l.31.31a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39Zm1.23-3.723a.75.75 0 0 0 .219-.53V2.929a.75.75 0 0 0-1.5 0V5.36l-.31-.31A7 7 0 0 0 3.239 8.188a.75.75 0 1 0 1.448.389A5.5 5.5 0 0 1 13.89 6.11l.311.31h-2.432a.75.75 0 0 0 0 1.5h4.243a.75.75 0 0 0 .53-.219Z" clip-rule="evenodd" />
                </svg>
                <span wire:loading.remove wire:target="refreshStock">Refresh Sekarang</span>
                <span wire:loading wire:target="refreshStock">Memuat...</span>
            </button>
        </div>

        @if (empty($stockData))
            <p class="text-sm text-gray-500 dark:text-gray-400 italic">Belum ada akun terdaftar.</p>
        @else
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:0.75rem;">
                @foreach ($stockData as $item)
                    @php
                        $stock    = $item['stockAvailable'];
                        $hasError = $item['error'] !== null;

                        $badgeColor = match (true) {
                            $hasError       => 'danger',
                            $stock === null => 'gray',
                            $stock === 0    => 'danger',
                            $stock <= 20    => 'warning',
                            default         => 'success',
                        };

                        $stockLabel = $hasError
                            ? $item['error']
                            : ($stock === null ? '-' : number_format((int) $stock, 0, ',', '.') . ' Tabung');
                    @endphp

                    <x-filament::section compact>
                        <x-slot name="heading">
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;min-width:0;">
                                <span style="font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    {{ $item['email'] }}
                                </span>
                                <x-filament::badge :color="$badgeColor" size="sm">
                                    {{ $stockLabel }}
                                </x-filament::badge>
                            </div>
                        </x-slot>

                        <div style="display:flex;flex-direction:column;gap:6px;margin-top:4px;">
                            @if (!$hasError)
                                @if ($item['storeName'])
                                    <div style="display:flex;align-items:center;gap:6px;">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" width="13" height="13" style="flex-shrink:0;color:#9ca3af;">
                                            <path fill-rule="evenodd" d="M4 1.75A2.75 2.75 0 0 0 1.25 4.5v1.448l.13-.064A3 3 0 0 1 4.75 9.354V14.5H11.5v-5.146a3 3 0 0 1 3.37-3.97l.13.063V4.5A2.75 2.75 0 0 0 12.25 1.75h-8.5ZM6.5 14.5V9.354a1.5 1.5 0 0 0-.922-1.386l-.578-.24V14.5h1.5Zm1.5 0h1.5V7.728l-.578.24A1.5 1.5 0 0 0 8 9.354V14.5Z" clip-rule="evenodd" />
                                        </svg>
                                        <span class="text-xs text-gray-600 dark:text-gray-400" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $item['storeName'] }}</span>
                                    </div>
                                @endif

                                @if ($item['stockDate'])
                                    <div style="display:flex;align-items:center;gap:6px;">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" width="13" height="13" style="flex-shrink:0;color:#9ca3af;">
                                            <path d="M5.75 7.5a.75.75 0 1 0 0 1.5.75.75 0 0 0 0-1.5ZM5 10.25a.75.75 0 1 1 1.5 0 .75.75 0 0 1-1.5 0ZM10.25 7.5a.75.75 0 1 0 0 1.5.75.75 0 0 0 0-1.5ZM9.5 10.25a.75.75 0 1 1 1.5 0 .75.75 0 0 1-1.5 0ZM8 7.5a.75.75 0 1 0 0 1.5.75.75 0 0 0 0-1.5ZM7.25 10.25a.75.75 0 1 1 1.5 0 .75.75 0 0 1-1.5 0Z" />
                                            <path fill-rule="evenodd" d="M4.75 1a.75.75 0 0 1 .75.75V3h5V1.75a.75.75 0 0 1 1.5 0V3h.75A2.25 2.25 0 0 1 15 5.25v7.5A2.25 2.25 0 0 1 12.75 15H3.25A2.25 2.25 0 0 1 1 12.75v-7.5A2.25 2.25 0 0 1 3.25 3H4V1.75A.75.75 0 0 1 4.75 1Zm-1.5 5.5A.75.75 0 0 1 4 6.75h8a.75.75 0 0 1 0 1.5H4A.75.75 0 0 1 3.25 6.5Z" clip-rule="evenodd" />
                                        </svg>
                                        <span class="text-xs text-gray-600 dark:text-gray-400">Stok tanggal: {{ $item['stockDate'] }}</span>
                                    </div>
                                @endif

                                @if ($item['lastSyncAt'])
                                    <div style="display:flex;align-items:center;gap:6px;">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" width="13" height="13" style="flex-shrink:0;color:#9ca3af;">
                                            <path fill-rule="evenodd" d="M1 8a7 7 0 1 1 14 0A7 7 0 0 1 1 8Zm7.75-4.25a.75.75 0 0 0-1.5 0V8c0 .414.336.75.75.75h3.25a.75.75 0 0 0 0-1.5h-2.5v-3.5Z" clip-rule="evenodd" />
                                        </svg>
                                        <span class="text-xs text-gray-600 dark:text-gray-400">Last sync: {{ $item['lastSyncAt'] }}</span>
                                    </div>
                                @endif

                                @if ($item['fetched_at'])
                                    <div style="margin-top:4px;padding-top:6px;border-top:1px solid #f3f4f6;">
                                        <span style="font-size:10px;color:#9ca3af;">Diambil pukul {{ $item['fetched_at'] }} &bull; cache 5 menit</span>
                                    </div>
                                @endif
                            @else
                                <p class="text-xs text-danger-600 dark:text-danger-400 italic">{{ $item['error'] }}</p>
                            @endif
                        </div>
                    </x-filament::section>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

<style>
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>
