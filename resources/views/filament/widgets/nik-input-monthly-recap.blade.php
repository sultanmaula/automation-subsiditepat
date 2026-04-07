<x-filament-widgets::widget>
    <x-filament::section collapsible>
        <x-slot name="heading">Rekap Input File &mdash; {{ $month }}</x-slot>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:0.75rem;">
            @forelse ($accounts as $account)
                <x-filament::section compact>
                    <x-slot name="heading">
                        <span class="text-xs font-semibold">{{ $account['name'] }}</span>
                    </x-slot>

                    <div class="flex flex-col gap-1">
                        @foreach ($account['documents'] as $doc)
                            @php
                                $color = match (true) {
                                    $doc['rotation'] >= 3 => 'danger',
                                    $doc['rotation'] === 2 => 'warning',
                                    default                => 'success',
                                };
                            @endphp
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate text-xs text-gray-600 dark:text-gray-400">
                                    {{ $doc['name'] }}
                                </span>
                                <x-filament::badge :color="$color" size="sm">
                                    {{ $doc['rotation'] }}x
                                </x-filament::badge>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400 italic">Belum ada input bulan ini.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
