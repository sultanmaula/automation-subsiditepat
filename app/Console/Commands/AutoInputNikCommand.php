<?php

namespace App\Console\Commands;

use App\Exceptions\StockExhaustedException;
use App\Jobs\NotifyNikChainCompleted;
use App\Jobs\ProcessNikJob;
use App\Models\Account;
use App\Models\AccountDocumentOrder;
use App\Models\DataNikInput;
use App\Models\NikInputHistory;
use App\Services\MerchantTokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Auto-input NIK harian: proses batch kecil (10 NIK) per run, dipanggil
 * TIAP 1 MENIT oleh scheduler -> pipeline granular yang tetap mengikuti
 * pola tombol manual "Input Data" di halaman Accounts, tapi dipecah supaya
 * kalau ada gangguan (mis. restart Horizon) yang "nyangkut" cuma maks 10
 * item, bukan 1 dokumen penuh.
 *
 * Catatan: kecepatan efektif TETAP dibatasi oleh rate limiter internal di
 * ProcessNikJob (10 hit/60 detik/akun) dan limit asli dari Pertamina sendiri
 * (429 / DAILY_LIMIT_TRANSACTION) -- batch 10/menit ini cuma restrukturisasi
 * pipeline, bukan cara melewati limit tersebut.
 *
 * Tiap run:
 *  1. Cek stok API Pertamina; bila 0 -> skip.
 *  2. Skip bila chain akun masih berjalan (guard idempotent, chain sebelumnya
 *     belum selesai -> jangan dispatch dobel).
 *  3. Cari dokumen aktif pertama (urut AccountDocumentOrder) yang masih punya
 *     NIK eligible, ambil maksimal BATCH_SIZE (10) NIK pertama dari dokumen
 *     itu, dispatch sebagai satu Bus::chain kecil.
 *  4. Dispatch Bus::chain(ProcessNikJob...) + NotifyNikChainCompleted.
 *
 * NIK eligible = belum 4x bulan ini, belum diblokir permanen (isBlocked
 * Dukcapil dsb.), belum dipakai hari ini, dan belum ditandai "nik_skip_today"
 * (gagal generic seperti TRANSACTION_INVALID di run sebelumnya hari ini ->
 * ProcessNikJob yang menandainya, supaya tidak diulang-ulang percuma).
 *
 * Stok "dapat dijual" di Pertamina bisa lebih rendah dari stockAvailable yang
 * dicek di awal -> StockExhaustedException per-NIK di-skip oleh ProcessNikJob,
 * chain LANJUT ke NIK berikutnya (tidak berhenti total).
 */
class AutoInputNikCommand extends Command
{
    protected $signature = 'nik:auto-input {account_id}';
    protected $description = 'Auto-input NIK harian: proses batch 10 NIK per run (dipanggil tiap 1 menit)';

    /** Jumlah NIK maksimal yang di-dispatch per run. */
    protected const BATCH_SIZE = 10;

    /** Batas putaran per NIK per bulan (sejalan dengan ProcessNikJob). */
    protected const MAX_ROTATION = 4;

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

        /** 1. STOK ----------------------------------------------------------- */
        try {
            $stockAvailable = $this->getStockAvailable($account);
        } catch (Throwable $e) {
            Log::error('[NIK][auto] gagal ambil stok', [
                'account_id' => $accountId,
                'error'      => $e->getMessage(),
            ]);
            return Command::SUCCESS; // fail-safe
        }

        if ($stockAvailable <= 0) {
            $this->info('Stock empty, skip today');
            return Command::SUCCESS;
        }

        /** 2. GUARD CHAIN BERJALAN --------------------------------------------- */
        $state = Cache::get("nik_progress:{$accountId}");
        if (is_array($state) && ($state['status'] ?? null) === 'running') {
            $this->info('Chain masih berjalan, skip');
            return Command::SUCCESS;
        }

        $lock = Cache::lock("auto_input_nik_lock:{$accountId}", 120);
        if (! $lock->get()) {
            $this->info('Run lain sedang memproses akun ini, skip');
            return Command::SUCCESS;
        }

        try {
            /** 3. URUTAN DOKUMEN PER AKUN ----------------------------------- */
            $documentOrders = AccountDocumentOrder::where('account_id', $accountId)
                ->orderBy('order')
                ->get();

            if ($documentOrders->isEmpty()) {
                $this->warn('No document order configured');
                return Command::SUCCESS;
            }

            /** 4. PILIH NIK (1 FILE PENUH) ----------------------------------- */
            $selected = $this->selectNiks($accountId, $month, $today, $documentOrders);

            if (empty($selected)) {
                $this->info('Tidak ada NIK yang memenuhi syarat');
                return Command::SUCCESS;
            }

            /** 5. DISPATCH CHAIN -------------------------------------------- */
            $this->dispatchChain($account, $selected);

            $this->info('Dispatched ' . count($selected) . ' job(s)');
            Log::info('[NIK][auto] selesai dispatch', [
                'account_id' => $accountId,
                'jobs'       => count($selected),
            ]);
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }

    /**
     * Cari dokumen aktif pertama (urut AccountDocumentOrder) yang masih
     * punya NIK eligible, lalu kembalikan maksimal BATCH_SIZE NIK pertama
     * dari dokumen itu (urut `order` ASC).
     *
     * @return array<int, array{document_id:int, nik_id:int}>
     */
    protected function selectNiks(
        int $accountId,
        string $month,
        string $today,
        $documentOrders
    ): array {
        foreach ($documentOrders as $docOrder) {
            $documentId = (int) $docOrder->data_master_document_id;

            // NIK dokumen ini, urut.
            $niks = DataNikInput::where('data_master_document_id', $documentId)
                ->orderBy('order')
                ->get(['id', 'nik', 'order']);

            if ($niks->isEmpty()) {
                continue;
            }

            // Jumlah input sukses bulan ini per NIK (sumber hitungan putaran).
            $monthCounts = NikInputHistory::where('account_id', $accountId)
                ->where('data_master_document_id', $documentId)
                ->where('input_month', $month)
                ->selectRaw('nik, COUNT(*) as c')
                ->groupBy('nik')
                ->pluck('c', 'nik')
                ->all();

            // NIK yang isBlocked permanen oleh Pertamina bulan ini (mis. status
            // MENINGGAL di Dukcapil) -> dikecualikan total dari rotasi.
            $blockedSet = array_flip(
                NikInputHistory::where('account_id', $accountId)
                    ->where('data_master_document_id', $documentId)
                    ->where('input_month', $month)
                    ->where('is_failed', true)
                    ->pluck('nik')
                    ->all()
            );

            // NIK yang sudah dipakai HARI INI (idempotency / re-run 15 menit).
            $usedTodaySet = array_flip(
                NikInputHistory::where('account_id', $accountId)
                    ->where('data_master_document_id', $documentId)
                    ->whereDate('input_date', $today)
                    ->pluck('nik')
                    ->all()
            );

            $selected = [];

            foreach ($niks as $n) {
                $count = (int) ($monthCounts[$n->nik] ?? 0);

                if ($count >= self::MAX_ROTATION) {
                    continue; // capped bulan ini
                }
                if (isset($blockedSet[$n->nik])) {
                    continue; // diblokir permanen (mis. isBlocked Dukcapil)
                }
                if (isset($usedTodaySet[$n->nik])) {
                    continue; // sudah dipakai hari ini
                }
                if (Cache::has("nik_skip_today:{$accountId}:{$n->nik}")) {
                    continue; // gagal generic hari ini (mis. TRANSACTION_INVALID) -> jangan diulang
                }

                $selected[] = ['document_id' => $documentId, 'nik_id' => (int) $n->id];

                if (count($selected) >= self::BATCH_SIZE) {
                    break;
                }
            }

            if (! empty($selected)) {
                return $selected; // 1 batch (maks BATCH_SIZE) dari dokumen aktif -> berhenti di sini
            }

            // Dokumen ini sudah habis (semua capped/blocked/dipakai) -> coba dokumen berikutnya.
        }

        return [];
    }

    /**
     * Susun & dispatch Bus::chain (mengikuti pola Filament "Input Data").
     *
     * @param array<int, array{document_id:int, nik_id:int}> $selected
     */
    protected function dispatchChain(Account $account, array $selected): void
    {
        $accountId    = (int) $account->id;
        $accountEmail = $account->email;
        $total        = count($selected);
        $runId        = (string) Str::uuid();
        $progressKey  = "nik_progress:{$accountId}";

        Cache::put($progressKey, [
            'run_id'     => $runId,
            'total'      => $total,
            'done'       => 0,
            'status'     => 'running',
            'started_at' => now()->toIso8601String(),
        ], now()->addHours(2));

        $jobs = [];
        foreach (array_values($selected) as $i => $item) {
            $jobs[] = new ProcessNikJob(
                account_id: $accountId,
                data_master_document_id: $item['document_id'],
                data_nik_input_id: $item['nik_id'],
                run_id: $runId,
                position: $i + 1,
                total: $total,
            );
        }

        // Tanpa user pemicu -> user_id null (NotifyNikChainCompleted aman handle null).
        $jobs[] = new NotifyNikChainCompleted($accountId, $total, null, $runId);

        Bus::chain($jobs)
            ->onConnection('redis')
            ->catch(function (Throwable $e) use ($accountEmail, $progressKey, $runId): void {
                $state = Cache::get($progressKey);
                if (is_array($state) && ($state['run_id'] ?? null) === $runId) {
                    $state['status'] = $e instanceof StockExhaustedException ? 'stopped' : 'failed';
                    Cache::put($progressKey, $state, now()->addMinute());
                }

                Log::info('[NIK][auto] chain berhenti', [
                    'account' => $accountEmail,
                    'reason'  => $e instanceof StockExhaustedException ? 'stock_exhausted' : 'error',
                    'error'   => $e->getMessage(),
                ]);
            })
            ->dispatch();
    }

    /**
     * Ambil sisa stok (stockAvailable) dari API Pertamina.
     * Disalin dari NikProcessCommand agar konsisten.
     */
    protected function getStockAvailable(Account $account): int
    {
        $token = MerchantTokenService::getOrFetch($account);
        if (! $token) {
            throw new RuntimeException('Merchant token not found');
        }

        $res = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->timeout(15)->get('https://api-map.my-pertamina.id/general/products/v1/products/user');

        if ($res->failed()) {
            throw new RuntimeException('Stock API failed');
        }

        return (int) ($res->json('data.stockAvailable') ?? 0);
    }
}
