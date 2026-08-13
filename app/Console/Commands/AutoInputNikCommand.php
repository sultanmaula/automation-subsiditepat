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
 *     NIK eligible, ambil maksimal BATCH_SIZE NIK dari dokumen itu dengan model
 *     "lanjut ketat dari last_nik_input": mulai dari NIK ber-`order` setelah NIK
 *     terakhir yang tersimpan, lalu wrap ke awal file untuk putaran berikutnya —
 *     urutan maju yang bisa diprediksi, bukan mengulang dari NIK pertama.
 *     Dispatch sebagai satu Bus::chain kecil.
 *  4. Dispatch Bus::chain(ProcessNikJob...) + NotifyNikChainCompleted.
 *
 * NIK eligible = belum 4x bulan ini, belum diblokir permanen (is_failed dari
 * NikBlockedException, mis. isBlocked Dukcapil), belum dipakai hari ini, dan
 * belum gagal transient >= MAX_DAILY_FAILS kali hari ini (counter cache dari
 * ProcessNikJob; reset tengah malam, jadi tidak menghanguskan NIK sebulan).
 * Error generic/transient (mis. 500 Pertamina) TIDAK menghanguskan NIK --
 * dicoba lagi di run berikutnya / besok.
 *
 * Stok "dapat dijual" di Pertamina bisa lebih rendah dari stockAvailable yang
 * dicek di awal -> StockExhaustedException per-NIK di-skip oleh ProcessNikJob,
 * chain LANJUT ke NIK berikutnya (tidak berhenti total).
 */
class AutoInputNikCommand extends Command
{
    protected $signature = 'nik:auto-input {account_id}';
    protected $description = 'Auto-input NIK harian: proses batch 10 NIK per run (dipanggil tiap 1 menit)';

    /**
     * Jumlah NIK maksimal yang di-dispatch per run. Diturunkan dari 10 ke 5
     * (Jul 2026) bersama pacing 30-90 detik di ProcessNikJob: ritme yang lebih
     * lambat & tidak seragam sebagai margin aman terhadap flag anti-abuse.
     */
    protected const BATCH_SIZE = 5;

    /**
     * Batas putaran per NIK per bulan. Dibaca juga oleh ProcessNikJob sebagai
     * guard terakhir — sengaja satu sumber angka supaya keduanya tidak bisa
     * lepas sinkron.
     */
    public const MAX_ROTATION = 4;

    /**
     * Batas kegagalan transient per NIK per hari (dicatat ProcessNikJob di
     * cache, hangus tengah malam). NIK yang sudah gagal sebanyak ini hari ini
     * diperlakukan seperti "sudah dipakai hari ini": di-skip agar dokumen bisa
     * terkuras & rotasi maju ke dokumen berikutnya, TANPA menghanguskan NIK
     * tersebut sebulan -- besok dia eligible lagi.
     */
    public const MAX_DAILY_FAILS = 2;

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

        /** 0. GUARD PENDINGINAN ------------------------------------------------
         * Akun ini baru saja kena 500/NOT_ACCEPTABLE beruntun (dokumen habis
         * atau gangguan sisi Pertamina). Tanpa gerbang ini scheduler mengirim
         * chain baru tiap menit dan terus menggedor.
         *
         * SENGAJA paling depan, sebelum cek stok: getStockAvailable() sendiri
         * memanggil API Pertamina, jadi kalau gerbang ini ditaruh sesudahnya
         * akun yang "didiamkan" tetap menembak sekali per menit — persis yang
         * ingin dihindari.
         */
        $cooldownUntil = Cache::get(ProcessNikJob::cooldownKey($accountId));

        if ($cooldownUntil && now()->lt($cooldownUntil)) {
            $this->info('Akun sedang didiamkan setelah deret error 500, skip');
            return Command::SUCCESS;
        }

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
     * punya NIK eligible, lalu kembalikan maksimal BATCH_SIZE NIK dari
     * dokumen itu.
     *
     * Urutan pemilihan di dalam dokumen = LANJUT KETAT dari `last_nik_input`:
     * ambil NIK ber-`order` tepat setelah NIK terakhir yang tersimpan, lalu
     * wrap ke awal file untuk putaran berikutnya. Tidak "mundur" mengisi NIK
     * ber-count rendah (frontier lama) supaya urutannya bisa diprediksi.
     * Semua filter kelayakan (capped 4x / diblokir / ditolak / dipakai hari
     * ini / gagal harian / diklaim akun lain) TETAP berlaku.
     *
     * @return array<int, array{document_id:int, nik_id:int}>
     */
    protected function selectNiks(
        int $accountId,
        string $month,
        string $today,
        $documentOrders
    ): array {
        // NIK sukses terakhir yang tersimpan di akun ini (titik lanjut).
        $lastNik = Account::whereKey($accountId)->value('last_nik_input');

        foreach ($documentOrders as $docOrder) {
            $documentId = (int) $docOrder->data_master_document_id;

            // NIK dokumen ini, urut.
            $niks = DataNikInput::where('data_master_document_id', $documentId)
                ->orderBy('order')
                ->get(['id', 'nik', 'order']);

            if ($niks->isEmpty()) {
                continue;
            }

            // Semua query di bawah SENGAJA tanpa filter account_id maupun
            // data_master_document_id: kuota Pertamina berlaku per NIK (per
            // konsumen) secara global. 13 dokumen dipasang ke lebih dari satu
            // akun, sehingga menghitung per akun membuat tiap akun mulai dari
            // nol — Juli 2026: 73 NIK terinput 8x (4x akun 1 + 4x akun 2).
            // Di-scope ke NIK dokumen ini saja supaya tetap efisien.
            $nikList = $niks->pluck('nik')->all();

            // Jumlah input SUKSES bulan ini per NIK (sumber hitungan putaran).
            // Baris dengan rejected_status / is_failed adalah penolakan, bukan
            // pembelian — jangan pernah dihitung sebagai putaran.
            $monthCounts = NikInputHistory::whereIn('nik', $nikList)
                ->where('input_month', $month)
                ->whereNull('rejected_status')
                ->where('is_failed', false)
                ->selectRaw('nik, COUNT(*) as c')
                ->groupBy('nik')
                ->pluck('c', 'nik')
                ->all();

            // NIK yang diblokir permanen bulan ini (NikBlockedException,
            // mis. isBlocked Dukcapil, atau NIK tidak terdaftar) -> dikecualikan
            // total dari rotasi.
            $blockedSet = array_flip(
                NikInputHistory::whereIn('nik', $nikList)
                    ->where('input_month', $month)
                    ->where('is_failed', true)
                    ->pluck('nik')
                    ->all()
            );

            // NIK yang sudah ditolak Pertamina bulan ini (kuota habis / sudah
            // dipakai pangkalan lain). Jawaban mereka otoritatif: hitungan lokal
            // tidak bisa melihat pembelian di luar sistem ini. Istirahatkan
            // sampai kuota reset bulan depan.
            $rejectedSet = array_flip(
                NikInputHistory::whereIn('nik', $nikList)
                    ->where('input_month', $month)
                    ->whereNotNull('rejected_status')
                    ->pluck('nik')
                    ->all()
            );

            // NIK yang sudah dipakai HARI INI (idempotency / re-run 15 menit).
            $usedTodaySet = array_flip(
                NikInputHistory::whereIn('nik', $nikList)
                    ->whereDate('input_date', $today)
                    ->pluck('nik')
                    ->all()
            );

            $eligible = [];

            foreach ($niks as $n) {
                $count = (int) ($monthCounts[$n->nik] ?? 0);

                if ($count >= self::MAX_ROTATION) {
                    continue; // capped bulan ini
                }
                if (isset($blockedSet[$n->nik])) {
                    continue; // diblokir permanen (mis. isBlocked Dukcapil)
                }
                if (isset($rejectedSet[$n->nik])) {
                    continue; // ditolak Pertamina bulan ini -> tunggu bulan depan
                }
                if (isset($usedTodaySet[$n->nik])) {
                    continue; // sudah dipakai hari ini
                }
                $eligible[] = [
                    'document_id' => $documentId,
                    'nik_id'      => (int) $n->id,
                    'nik'         => $n->nik,
                    'count'       => $count,
                    'order'       => (int) $n->order,
                ];
            }

            // NIK yang hari ini sudah gagal transient >= MAX_DAILY_FAILS kali
            // (mis. 429/token basi berulang) dianggap "dipakai hari ini":
            // tanpa ini, segelintir NIK yang terus gagal membuat dokumen tak
            // pernah dianggap habis dan rotasi macet di dokumen tersebut.
            if (! empty($eligible)) {
                $failCounts = Cache::many(array_map(
                    fn (array $e) => ProcessNikJob::dailyFailKey($accountId, $e['nik']),
                    $eligible
                ));

                $eligible = array_values(array_filter(
                    $eligible,
                    fn (array $e) => (int) ($failCounts[ProcessNikJob::dailyFailKey($accountId, $e['nik'])] ?? 0) < self::MAX_DAILY_FAILS
                ));
            }

            // NIK yang sudah "dipesan" chain akun lain yang masih berjalan.
            // Chain berjalan asinkron, jadi baris history-nya belum ada saat
            // akun berikutnya menyeleksi di menit yang sama — tanpa klaim,
            // dua akun bisa memilih NIK yang sama untuk hari yang sama.
            if (! empty($eligible)) {
                $claims = Cache::many(array_map(
                    fn (array $e) => ProcessNikJob::claimKey($e['nik']),
                    $eligible
                ));

                $eligible = array_values(array_filter(
                    $eligible,
                    fn (array $e) => blank($claims[ProcessNikJob::claimKey($e['nik'])] ?? null)
                ));
            }

            if (! empty($eligible)) {
                // Urutan KETAT: selalu maju berdasarkan posisi `order` di file.
                usort($eligible, fn (array $a, array $b) => $a['order'] <=> $b['order']);

                // Titik lanjut = `order` milik last_nik_input BILA NIK itu ada di
                // dokumen ini (dihitung dari seluruh NIK, bukan hanya yang
                // eligible, supaya last_nik_input yang sudah capped tetap jadi
                // acuan). Ambil dulu yang order-nya SETELAH titik itu; kalau
                // sudah mentok ujung file, wrap ke NIK ber-order <= titik
                // (putaran berikutnya). Kalau last_nik_input bukan dari dokumen
                // ini (mis. dokumen berikutnya), mulai dari awal file.
                $lastOrder = $lastNik !== null
                    ? $niks->firstWhere('nik', $lastNik)?->order
                    : null;

                if ($lastOrder !== null) {
                    $lastOrder = (int) $lastOrder;
                    $after = array_values(array_filter($eligible, fn (array $e) => $e['order'] > $lastOrder));
                    $wrap  = array_values(array_filter($eligible, fn (array $e) => $e['order'] <= $lastOrder));
                    $eligible = array_merge($after, $wrap);
                }

                return array_map(
                    fn (array $e) => [
                        'document_id' => $e['document_id'],
                        'nik_id'      => $e['nik_id'],
                        'nik'         => $e['nik'],
                    ],
                    array_slice($eligible, 0, self::BATCH_SIZE)
                ); // 1 batch (maks BATCH_SIZE) dari dokumen aktif -> berhenti di sini
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
        $niks         = array_column($selected, 'nik');

        Cache::put($progressKey, [
            'run_id'     => $runId,
            'total'      => $total,
            'done'       => 0,
            'status'     => 'running',
            'started_at' => now()->toIso8601String(),
            // Dipakai ProcessNikJob::finishChain() untuk melepas klaim NIK yang
            // sudah dipesan tapi tidak jadi diproses.
            'niks'       => $niks,
        ], now()->addHours(2));

        // Pesan NIK ini supaya akun lain yang menyeleksi sebelum chain ini
        // menulis history tidak ikut memilihnya. TTL 2 jam adalah jaring
        // pengaman kalau job hilang; normalnya dilepas ProcessNikJob.
        foreach ($niks as $nik) {
            Cache::put(ProcessNikJob::claimKey((string) $nik), $accountId, now()->addHours(2));
        }

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
