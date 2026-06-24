@php
    /** @var \App\Models\Account $record */
    $record = $getRecord();
    $state = \Illuminate\Support\Facades\Cache::get("nik_progress:{$record->id}");

    $total = (int) ($state['total'] ?? 0);
    $done = (int) ($state['done'] ?? 0);
    $status = $state['status'] ?? null;
    $percent = $total > 0 ? min(100, (int) round($done / $total * 100)) : 0;

    $meta = match ($status) {
        'completed' => ['label' => 'Selesai', 'bar' => 'bg-success-500', 'badge' => 'text-success-700 bg-success-50'],
        'stopped'   => ['label' => 'Berhenti', 'bar' => 'bg-warning-500', 'badge' => 'text-warning-700 bg-warning-50'],
        'failed'    => ['label' => 'Gagal', 'bar' => 'bg-danger-500', 'badge' => 'text-danger-700 bg-danger-50'],
        'running'   => ['label' => 'Berjalan', 'bar' => 'bg-primary-500', 'badge' => 'text-primary-700 bg-primary-50'],
        default     => null,
    };
@endphp

@if (! is_array($state) || $meta === null || $total <= 0)
    <span class="text-sm text-gray-400">-</span>
@else
    <div class="flex w-40 flex-col gap-1">
        <div class="flex items-center justify-between gap-2">
            <span class="text-xs font-semibold text-gray-700 dark:text-gray-200">{{ $done }} / {{ $total }} NIK</span>
            <span class="rounded-full px-1.5 py-0.5 text-[10px] font-medium {{ $meta['badge'] }}">{{ $meta['label'] }}</span>
        </div>
        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
            <div class="h-full rounded-full transition-all duration-500 {{ $meta['bar'] }}" style="width: {{ $percent }}%"></div>
        </div>
    </div>
@endif
