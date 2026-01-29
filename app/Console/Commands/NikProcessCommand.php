<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Account;
use App\Models\AccountDocumentOrder;
use App\Models\DataNikInput;
use App\Models\NikInputHistory;
use App\Jobs\ProcessNikJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class NikProcessCommand extends Command
{
    protected $signature = 'nik:process {account_id}';
    protected $description = 'Dispatch NIK jobs with wrap & rotation logic (post-migration)';

    public function handle(): int
    {
        $accountId = (int) $this->argument('account_id');

        $account = Account::find($accountId);
        if (! $account) {
            $this->error('Account not found');
            return Command::FAILURE;
        }

        $today = now()->toDateString();
        $month = now()->format('Y-m');

        /** =========================
         * CONFIG
         * ========================= */
        $dailyLimit = 205;

        /** =========================
         * 1. AMBIL STOCK AVAILABLE
         * ========================= */
        try {
            $stockAvailable = $this->getStockAvailable($account);
        } catch (\Throwable $e) {
            Log::error('[NIK] Failed fetch stock', [
                'account_id' => $accountId,
                'error'      => $e->getMessage(),
            ]);
            return Command::SUCCESS; // fail-safe
        }

        if ($stockAvailable <= 0) {
            $this->info('Stock empty, skip today');
            return Command::SUCCESS;
        }

        /** =========================
         * 2. HITUNG QUOTA HARIAN
         * ========================= */
        $processedToday = NikInputHistory::where('account_id', $accountId)
            ->whereDate('input_date', $today)
            ->count();

        $remainingQuota = min(
            max(0, $dailyLimit - $processedToday),
            $stockAvailable
        );

        if ($remainingQuota <= 0) {
            $this->info('Quota or stock exhausted');
            return Command::SUCCESS;
        }

        /** =========================
         * 3. AMBIL URUTAN DOKUMEN (PER ACCOUNT)
         * ========================= */
        $documentOrders = AccountDocumentOrder::where('account_id', $accountId)
            ->orderBy('order')
            ->get();

        if ($documentOrders->isEmpty()) {
            $this->warn('No document order configured');
            return Command::SUCCESS;
        }

        $jobsDispatched = 0;

        /** =========================
         * 4. LOOP DOKUMEN
         * ========================= */
        foreach ($documentOrders as $docOrder) {

            if ($remainingQuota <= 0) {
                break;
            }

            $documentId = $docOrder->data_master_document_id;

            /** =========================
             * 4a. TOTAL NIK DALAM DOKUMEN
             * ========================= */
            $totalNikInDocument = DataNikInput::where('data_master_document_id', $documentId)->count();
            if ($totalNikInDocument === 0) {
                continue;
            }

            /** =========================
             * 4b. TOTAL NIK TERPAKAI BULAN INI
             * ========================= */
            $usedNikCount = NikInputHistory::where('account_id', $accountId)
                ->where('data_master_document_id', $documentId)
                ->where('input_month', $month)
                ->count();

            /** =========================
             * 4c. HITUNG ROTASI (MENTOK 4x)
             * ========================= */
            $rotationCount = intdiv($usedNikCount, $totalNikInDocument);

            if ($rotationCount >= 4) {
                // dokumen ini sudah mentok bulan ini
                continue;
            }

            /** =========================
             * 4d. HITUNG POSISI TERAKHIR (MOD)
             * ========================= */
            $lastOrder = $usedNikCount % $totalNikInDocument;
            $currentOrder = $lastOrder;

            /**
             * =========================
             * 4e. LOOP NIK (WRAP)
             * =========================
             */
            while ($remainingQuota > 0 && $rotationCount < 4) {

                $niks = DataNikInput::where('data_master_document_id', $documentId)
                    ->where('order', '>', $currentOrder)
                    ->whereRaw(
                        '(SELECT COUNT(*) FROM nik_input_histories h
                          WHERE h.account_id = ?
                          AND h.nik = data_nik_inputs.nik
                          AND h.input_month = ?) < 4',
                        [$accountId, $month]
                    )
                    ->orderBy('order')
                    ->limit($remainingQuota)
                    ->get();

                if ($niks->isEmpty()) {
                    // WRAP ke awal dokumen
                    $currentOrder = 0;
                    $rotationCount++;
                    continue;
                }

                foreach ($niks as $nik) {

                    if ($remainingQuota <= 0) {
                        break 2;
                    }

                    // Safety: 1 NIK / hari
                    $alreadyUsedToday = NikInputHistory::where('account_id', $accountId)
                        ->where('nik', $nik->nik)
                        ->whereDate('input_date', $today)
                        ->exists();

                    if ($alreadyUsedToday) {
                        continue;
                    }

                    ProcessNikJob::dispatch(
                        account_id: $accountId,
                        data_master_document_id: $documentId,
                        data_nik_input_id: $nik->id
                    );

                    $jobsDispatched++;
                    $remainingQuota--;
                    $currentOrder = $nik->order;
                }
            }
        }

        $this->info("Dispatched {$jobsDispatched} job(s)");

        Log::info('[NIK] Command finished', [
            'account_id' => $accountId,
            'jobs'       => $jobsDispatched,
        ]);

        return Command::SUCCESS;
    }

    /** =========================
     * HELPER: GET STOCK AVAILABLE
     * ========================= */
    protected function getStockAvailable(Account $account): int
    {
        if (!Cache::get("merchant_api_token_{$account->email}") || Cache::get("merchant_api_token_{$account->email}") == NULL)
            Artisan::call('merchant:fetch-token', [
                '--email' => $account->email,
                '--pin' => $account->pin,
            ]);

        $token = Cache::get("merchant_api_token_{$account->email}");
        if (! $token) {
            throw new RuntimeException('Merchant token not found');
        }

        $res = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->get('https://api-map.my-pertamina.id/general/products/v1/products/user');

        if ($res->failed()) {
            throw new RuntimeException('Stock API failed');
        }

        return (int) ($res->json('data.stockAvailable') ?? 0);
    }
}
