<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Account;
use App\Models\DataNikInput;
use App\Models\NikInputHistory;
use Illuminate\Support\Carbon;

class MigrateLastNikStateCommand extends Command
{
    protected $signature = 'nik:migrate-last-state
        {--assume-rotation=0 : Default assumed rotation jika tidak ada di map}
        {--assume-rotation-map= : Format accountId:documentId:rotation,accountId:documentId:rotation}
        {--dry-run : Simulasi tanpa insert ke database}';

    protected $description = 'Bootstrap nik_input_histories with per-account per-document assumed rotations (safe with unique constraint)';

    public function handle(): int
    {
        $now = now();
        $month = $now->format('Y-m');
        $dryRun = (bool) $this->option('dry-run');
        $fallbackRotation = (int) $this->option('assume-rotation');

        /**
         * =====================================================
         * PARSE ROTATION MAP
         * Format: accountId:documentId:rotation
         * =====================================================
         */
        $rotationMap = [];

        if ($this->option('assume-rotation-map')) {
            foreach (explode(',', $this->option('assume-rotation-map')) as $pair) {

                $segments = array_map('trim', explode(':', $pair));
                if (count($segments) !== 3) {
                    continue;
                }

                [$accountId, $documentId, $rotation] = $segments;

                if (is_numeric($accountId) && is_numeric($documentId) && is_numeric($rotation)) {
                    $rotationMap[(int) $accountId][(int) $documentId] = (int) $rotation;
                }
            }
        }

        $this->info('=== NIK MIGRATION START ===');
        $this->info("Month        : {$month}");
        $this->info("Dry run      : " . ($dryRun ? 'YES' : 'NO'));
        $this->info('Rotation map : ' . (empty($rotationMap) ? 'NONE' : json_encode($rotationMap)));

        /**
         * =====================================================
         * LOOP ACCOUNT
         * =====================================================
         */
        $accounts = Account::whereNotNull('last_nik_input')->get();

        if ($accounts->isEmpty()) {
            $this->warn('No accounts with last_nik_input found');
            return Command::SUCCESS;
        }

        foreach ($accounts as $account) {

            $this->line('');
            $this->line("▶ Account {$account->id} ({$account->email})");

            /**
             * =====================================================
             * LAST NIK (LEGACY)
             * =====================================================
             */
            $lastNik = DataNikInput::where('nik', $account->last_nik_input)->first();

            if (! $lastNik) {
                $this->warn('  - last_nik_input not found in data_nik_inputs');
                continue;
            }

            $lastDocumentId = $lastNik->data_master_document_id;
            $lastOrder = $lastNik->order + 2;

            /**
             * =====================================================
             * 1. APPLY FULL ROTATION PER DOCUMENT (MAP)
             * =====================================================
             */
            foreach ($rotationMap[$account->id] ?? [] as $documentId => $rotationCount) {

                if ($rotationCount <= 0) {
                    continue;
                }

                $allNik = DataNikInput::where('data_master_document_id', $documentId)
                    ->orderBy('order')
                    ->get();

                if ($allNik->isEmpty()) {
                    continue;
                }

                $this->line("  - Document {$documentId}: applying {$rotationCount} full rotation(s)");

                for ($r = 1; $r <= $rotationCount; $r++) {

                    /**
                     * Synthetic date:
                     * - Tetap di bulan yang sama
                     * - Unik per rotation
                     */
                    $syntheticDate = Carbon::createFromDate(
                        $now->year,
                        $now->month,
                        min($r, $now->daysInMonth)
                    )->toDateString();

                    foreach ($allNik as $nikInput) {

                        if ($dryRun) {
                            continue;
                        }

                        NikInputHistory::firstOrCreate(
                            [
                                'account_id' => $account->id,
                                'nik'        => $nikInput->nik,
                                'input_date' => $syntheticDate,
                            ],
                            [
                                'data_master_document_id' => $documentId,
                                'data_nik_input_id'       => $nikInput->id,
                                'input_month'             => $month,
                            ]
                        );
                    }
                }
            }

            /**
             * =====================================================
             * 2. APPLY PARTIAL ROTATION (LAST NIK DOCUMENT ONLY)
             * =====================================================
             */
            $partialNik = DataNikInput::where('data_master_document_id', $lastDocumentId)
                ->where('order', '<=', $lastOrder)
                ->orderBy('order')
                ->get();

            if (! $partialNik->isEmpty()) {

                $partialDate = Carbon::createFromDate(
                    $now->year,
                    $now->month,
                    min(22, $now->daysInMonth) // aman dari collision
                )->toDateString();

                $this->line("  - Document {$lastDocumentId}: applying partial rotation until order {$lastOrder}");

                foreach ($partialNik as $nikInput) {

                    if ($dryRun) {
                        continue;
                    }

                    NikInputHistory::firstOrCreate(
                        [
                            'account_id' => $account->id,
                            'nik'        => $nikInput->nik,
                            'input_date' => $partialDate,
                        ],
                        [
                            'data_master_document_id' => $lastDocumentId,
                            'data_nik_input_id'       => $nikInput->id,
                            'input_month'             => $month,
                        ]
                    );
                }
            }

            /**
             * =====================================================
             * 3. SUMMARY
             * =====================================================
             */
            $summary = NikInputHistory::selectRaw(
                'data_master_document_id, COUNT(*) as total'
            )
                ->where('account_id', $account->id)
                ->where('input_month', $month)
                ->groupBy('data_master_document_id')
                ->get();

            foreach ($summary as $row) {
                $this->info("  ✔ Document {$row->data_master_document_id}: {$row->total} history record(s)");
            }
        }

        $this->info('');
        $this->info('=== NIK MIGRATION FINISHED ===');

        return Command::SUCCESS;
    }
}
