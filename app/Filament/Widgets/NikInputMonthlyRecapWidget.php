<?php

namespace App\Filament\Widgets;

use App\Models\DataNikInput;
use App\Models\NikInputHistory;
use Filament\Widgets\Widget;

class NikInputMonthlyRecapWidget extends Widget
{
    protected string $view = 'filament.widgets.nik-input-monthly-recap';

    protected int|string|array $columnSpan = 1;

    protected static ?int $sort = 3;

    protected function getViewData(): array
    {
        $currentMonth = now()->format('Y-m');

        $histories = NikInputHistory::with(['account', 'document'])
            ->where('input_month', $currentMonth)
            ->orderBy('account_id')
            ->orderBy('created_at', 'desc')
            ->get();

        if ($histories->isEmpty()) {
            return ['accounts' => [], 'month' => now()->translatedFormat('F Y')];
        }

        $documentIds = $histories->pluck('data_master_document_id')->unique();

        $nikCountPerDocument = DataNikInput::whereIn('data_master_document_id', $documentIds)
            ->selectRaw('data_master_document_id, COUNT(*) as total')
            ->groupBy('data_master_document_id')
            ->pluck('total', 'data_master_document_id');

        $inputsPerAccountDoc = NikInputHistory::where('input_month', $currentMonth)
            ->selectRaw('account_id, data_master_document_id, COUNT(*) as total')
            ->groupBy('account_id', 'data_master_document_id')
            ->get()
            ->groupBy('account_id')
            ->map(fn($rows) => $rows->keyBy('data_master_document_id'));

        $accounts = [];

        foreach ($histories->groupBy('account_id') as $accountId => $accountHistories) {
            $account   = $accountHistories->first()->account;
            $documents = [];

            foreach ($accountHistories->groupBy('data_master_document_id') as $docId => $docHistories) {
                $last        = $docHistories->first();
                $totalNiks   = $nikCountPerDocument->get($docId, 0);
                $totalInputs = (int) ($inputsPerAccountDoc->get($accountId)?->get($docId)?->total ?? 0);
                $rotation    = $totalNiks > 0 ? (int) ceil($totalInputs / $totalNiks) : 1;

                $documents[] = [
                    'name'     => $last->document?->original_name ?? 'Unknown Document',
                    'rotation' => $rotation,
                ];
            }

            $accounts[] = [
                'name'      => $account?->email ?? 'Unknown',
                'documents' => $documents,
            ];
        }

        return ['accounts' => $accounts, 'month' => now()->translatedFormat('F Y')];
    }
}
