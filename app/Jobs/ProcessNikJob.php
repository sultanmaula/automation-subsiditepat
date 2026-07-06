<?php

namespace App\Jobs;

use App\Exceptions\NikBlockedException;
use App\Exceptions\PertaminaRateLimitedException;
use App\Exceptions\StockExhaustedException;
use App\Exceptions\TokenStaleException;
use App\Models\Account;
use App\Models\DataNikInput;
use App\Models\NikInputHistory;
use App\Services\MerchantTokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

class ProcessNikJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * =====================================================
     * QUEUE CONFIG
     * =====================================================
     */
    public int $tries = 20;                 // cukup untuk antre rate limit
    public array $backoff = [10, 30, 60];   // fallback kalau error beneran

    /**
     * Job boleh retry maksimal 1 jam
     * (rate limit ≠ error)
     */
    public function retryUntil(): \DateTime
    {
        return now()->addHour();
    }

    /**
     * =====================================================
     * PAYLOAD
     * =====================================================
     */
    public function __construct(
        public int $account_id,
        public int $data_master_document_id,
        public int $data_nik_input_id,
        public string $run_id = '',
        public int $position = 0,
        public int $total = 0,
    ) {}

    /**
     * Key cache progres per akun.
     */
    public function progressKey(): string
    {
        return "nik_progress:{$this->account_id}";
    }

    /**
     * Tandai NIK posisi ini sudah mencapai status final.
     *
     * Chain berjalan strict berurutan, jadi `done = max(done, position)`
     * aman dari double-count: rate-limit release() tidak memanggil ini,
     * sehingga job yang dijalankan ulang tidak menaikkan progres dua kali.
     * Diabaikan bila run_id tidak cocok (state sudah ditimpa run baru).
     */
    protected function markProgress(): void
    {
        if ($this->run_id === '') {
            return;
        }

        $state = Cache::get($this->progressKey());

        if (! is_array($state) || ($state['run_id'] ?? null) !== $this->run_id) {
            return;
        }

        $state['done'] = max((int) ($state['done'] ?? 0), $this->position);

        Cache::put($this->progressKey(), $state, now()->addHours(2));
    }

    /**
     * =====================================================
     * HANDLE
     * =====================================================
     */
    public function handle(): void
    {
        $today = now()->toDateString();
        $month = now()->format('Y-m');

        /** =========================
         * 1. LOAD DATA
         * ========================= */
        $account = Account::findOrFail($this->account_id);
        $nikInput = DataNikInput::findOrFail($this->data_nik_input_id);

        /** =========================
         * 2. IDEMPOTENCY (1 NIK / HARI)
         * ========================= */
        $alreadyToday = NikInputHistory::where('account_id', $this->account_id)
            ->where('nik', $nikInput->nik)
            ->whereDate('input_date', $today)
            ->exists();

        if ($alreadyToday) {
            $this->markProgress();
            return; // job dianggap sukses
        }

        /** =========================
         * 3. LIMIT BULANAN (4x / NIK)
         * ========================= */
        $monthHistory = NikInputHistory::where('account_id', $this->account_id)
            ->where('nik', $nikInput->nik)
            ->where('input_month', $month)
            ->get(['is_failed']);

        // NIK ditandai isBlocked (mis. status MENINGGAL di Dukcapil) bulan ini ->
        // permanen skip, tidak pernah dicoba ulang (lihat NikBlockedException di bawah).
        if ($monthHistory->contains('is_failed', true)) {
            $this->markProgress();
            return;
        }

        if ($monthHistory->count() >= 4) {
            $this->markProgress();
            return;
        }

        /** =========================
         * 4. RATE LIMIT API
         * 10 HIT / 60 DETIK / ACCOUNT
         * ========================= */
        $rateKey = "pertamina-api-account-{$this->account_id}";

        if (RateLimiter::tooManyAttempts($rateKey, 10)) {

            $delay = max(
                RateLimiter::availableIn($rateKey),
                10
            );

            Log::info('[NIK] Rate limited, releasing job', [
                'account_id' => $this->account_id,
                'nik'        => $nikInput->nik,
                'delay'      => $delay,
            ]);

            $this->release($delay);
            return;
        }

        // KUNCI SLOT RATE LIMIT
        RateLimiter::hit($rateKey, 60);

        /** =========================
         * 5-6. TOKEN + CALL API PERTAMINA
         *
         * Strategi error (agar cocok untuk Bus::chain):
         *  - StockExhaustedException -> log + return: SKIP NIK ini, chain
         *    LANJUT ke NIK berikutnya (stok "dapat dijual" real-time dari
         *    Pertamina bisa lebih rendah dari stockAvailable yang dicek di
         *    awal, jadi ini bukan alasan untuk hentikan seluruh chain).
         *  - error lain (network/verify/submit gagal) -> log + return:
         *    SKIP NIK ini, chain LANJUT ke NIK berikutnya. NIK yang dilewati
         *    tidak tersimpan di history sehingga akan dicoba lagi di run berikutnya.
         * ========================= */
        try {
            $bearerToken = MerchantTokenService::getOrFetch($account);
            if (! $bearerToken) {
                throw new RuntimeException('Merchant token not available');
            }

            $verifyData = $this->verifyNik($bearerToken, $nikInput->nik, $account);
            $this->submitTransaction($bearerToken, $nikInput->nik, $verifyData, $account);
        } catch (StockExhaustedException $e) {
            // Pertamina menolak transaksi NIK ini karena stok "yang dapat
            // dijual" sudah menipis/habis di sisi mereka (independen dari
            // stockAvailable yang dicek sebelum dispatch) -> skip NIK ini
            // saja, lanjut ke NIK berikutnya. Tidak tersimpan di history
            // sehingga akan dicoba lagi di run berikutnya.
            Log::error('[NIK] Stock exhausted untuk NIK ini, skip & lanjut', [
                'account_id'   => $this->account_id,
                'nik'          => $nikInput->nik,
                'nik_input_id' => $this->data_nik_input_id,
                'error'        => $e->getMessage(),
            ]);
            $this->markProgress();
            return;
        } catch (NikBlockedException $e) {
            // NIK ditolak permanen oleh Pertamina (mis. status MENINGGAL di Dukcapil).
            // Simpan is_failed=true supaya tidak dihitung sebagai stok terpakai, TAPI
            // tetap tercatat sehingga frontier rotasi per-file bisa maju melewatinya
            // dan NIK ini tidak dicoba ulang lagi bulan ini.
            Log::warning('[NIK] NIK blocked, skip permanen bulan ini', [
                'account_id'   => $this->account_id,
                'nik'          => $nikInput->nik,
                'nik_input_id' => $this->data_nik_input_id,
                'reason'       => $e->getMessage(),
            ]);

            try {
                NikInputHistory::firstOrCreate(
                    [
                        'account_id' => $this->account_id,
                        'nik'        => $nikInput->nik,
                        'input_date' => $today,
                    ],
                    [
                        'data_master_document_id' => $this->data_master_document_id,
                        'data_nik_input_id'       => $nikInput->id,
                        'input_month'             => $month,
                        'is_failed'               => true,
                    ]
                );
            } catch (QueryException $qe) {
                if ($qe->getCode() !== '23505') {
                    throw $qe;
                }
            }

            $this->markProgress();
            return;
        } catch (PertaminaRateLimitedException $e) {
            // Rate limit asli dari Pertamina, bukan soal token -> retry NIK yang
            // sama setelah countDownTime, JANGAN markProgress (bukan sukses/gagal
            // permanen, cuma perlu ditunda).
            Log::warning('[NIK] Pertamina rate-limit, retry setelah countdown', [
                'account_id' => $this->account_id,
                'nik'        => $nikInput->nik,
                'delay'      => $e->retryAfterSeconds,
            ]);
            $this->release($e->retryAfterSeconds);
            return;
        } catch (TokenStaleException $e) {
            // Token dianggap valid secara lokal (cache TTL 14 menit) tapi ditolak
            // Pertamina eksplisit 401/403. Token sudah di-invalidate() di titik
            // lempar exception ini, jadi NIK berikutnya di chain otomatis pakai
            // token segar -> skip NIK ini saja & lanjut (bukan retry NIK yang sama),
            // konsisten dengan aturan: error selain rate-limit = skip & lanjut.
            Log::error('[NIK] Token basi terdeteksi, skip NIK ini & lanjut', [
                'account_id'   => $this->account_id,
                'nik'          => $nikInput->nik,
                'nik_input_id' => $this->data_nik_input_id,
                'reason'       => $e->getMessage(),
            ]);
            $this->markProgress();
            return;
        } catch (Throwable $e) {
            Log::error('[NIK] Job skip (error, lanjut NIK berikutnya)', [
                'account_id'   => $this->account_id,
                'nik'          => $nikInput->nik,
                'nik_input_id' => $this->data_nik_input_id,
                'error'        => $e->getMessage(),
            ]);

            // Tidak tercatat di NikInputHistory (supaya bisa dicoba lagi besok/bulan
            // depan), TAPI ditandai "skip hari ini" agar rotasi frontier auto-input
            // tidak berulang kali memilih NIK bermasalah yang sama di run 15 menit
            // berikutnya pada hari yang sama (lihat AutoInputNikCommand::selectNiks()).
            Cache::put(
                "nik_skip_today:{$this->account_id}:{$nikInput->nik}",
                true,
                now()->endOfDay()
            );

            $this->markProgress();
            return; // skip & lanjut: chain tetap jalan ke NIK berikutnya
        }

        /** =========================
         * 7. SAVE HISTORY (SAFE UNIQUE)
         * ========================= */
        try {
            NikInputHistory::firstOrCreate(
                [
                    'account_id' => $this->account_id,
                    'nik'        => $nikInput->nik,
                    'input_date' => $today,
                ],
                [
                    'data_master_document_id' => $this->data_master_document_id,
                    'data_nik_input_id'       => $nikInput->id,
                    'input_month'             => $month,
                ]
            );
        } catch (QueryException $e) {
            // safety net unique constraint
            if ($e->getCode() !== '23505') {
                throw $e;
            }
        }

        /** =========================
         * 8. UPDATE ACCOUNT (LEGACY)
         * ========================= */
        $account->update([
            'last_nik_input'  => $nikInput->nik,
            'last_update_api' => now(),
        ]);

        $this->markProgress();

        Log::info('[NIK] Job success', [
            'account_id' => $this->account_id,
            'nik'        => $nikInput->nik,
        ]);
    }

    /**
     * =====================================================
     * API HELPERS
     * =====================================================
     */
    protected function verifyNik(string $bearerToken, string $nik, Account $account): array
    {
        $res = Http::withHeaders([
            'Authorization' => 'Bearer ' . $bearerToken,
        ])->get(
            'https://api-map.my-pertamina.id/customers/v2/verify-nik',
            ['nationalityId' => $nik]
        );

        $json = $res->json();

        if (($json['code'] ?? null) === 429 || ($json['status'] ?? null) === 'TOO_MANY_REQUEST') {
            $delay = max((int) ($json['data']['countDownTime'] ?? 10), 10);

            throw new PertaminaRateLimitedException('Verify-NIK rate limited: ' . $res->body(), $delay);
        }

        if (in_array($res->status(), [401, 403], true)) {
            // Token cache masih dianggap valid secara lokal (TTL 14 menit) tapi
            // sudah kedaluwarsa di sisi Pertamina -> buang supaya attempt
            // berikutnya fetch token baru, bukan mengulang token basi ini.
            MerchantTokenService::invalidate($account);
            throw new TokenStaleException('Verify-NIK: ' . $res->body());
        }

        if ($res->failed()) {
            throw new RuntimeException('Verify-NIK failed: ' . $res->body());
        }

        $data = $res->json('data');

        if ($data['isBlocked'] ?? false) {
            throw new NikBlockedException(
                (string) ($data['reason']['message'] ?? 'NIK isBlocked oleh Pertamina')
            );
        }

        foreach (['token', 'familyIdEncrypted', 'name'] as $key) {
            if (blank($data[$key] ?? null)) {
                throw new RuntimeException("Verify-NIK missing {$key}");
            }
        }

        return $data;
    }

    protected function submitTransaction(
        string $bearerToken,
        string $nik,
        array $verifyData,
        Account $account
    ): void {
        $payload = [
            'quantity'          => '1',
            'token'             => (string) $verifyData['token'],
            'nationalityId'     => $nik,
            'familyIdEncrypted' => (string) $verifyData['familyIdEncrypted'],
            'category'          => $verifyData['customerTypes'][0]['name'] ?? 'Rumah Tangga',
            'sourceTypeId'      => '1',
            'name'              => (string) $verifyData['name'],
            'channelinject'     => $verifyData['channelInject'] ?? 'tnp2k',
        ];

        $res = Http::withHeaders([
            'Authorization' => 'Bearer ' . $bearerToken,
            'Accept'        => '*/*',
            'User-Agent'    => 'PostmanRuntime/7.49.0',
        ])->asMultipart()->post(
            'https://api-map.my-pertamina.id/general/v3/transactions',
            $payload
        );

        $json = $res->json();

        // Rate limit ASLI dari Pertamina (di luar RateLimiter internal kita
        // 10 hit/60 detik/akun) -> BUKAN soal token, jangan invalidate. Tunggu
        // sesuai countDownTime lalu retry NIK yang sama dengan token yang sama.
        if (($json['code'] ?? null) === 429 || ($json['status'] ?? null) === 'TOO_MANY_REQUEST') {
            $delay = max((int) ($json['data']['countDownTime'] ?? 10), 10);

            throw new PertaminaRateLimitedException('Submit-transaction rate limited: ' . $res->body(), $delay);
        }

        // Token masih dianggap valid secara lokal (cache TTL 14 menit) tapi
        // ditolak Pertamina di step submit -> baru bisa dipastikan token basi
        // kalau balasannya eksplisit 401/403 (lihat juga verifyNik()).
        if (in_array($res->status(), [401, 403], true)) {
            MerchantTokenService::invalidate($account);
            throw new TokenStaleException('Submit-transaction: ' . $res->body());
        }

        if (
            ($json['code'] ?? null) === 400
            && ($json['message'] ?? null) === 'Transaksi melebihi stok yang dapat dijual'
        ) {
            throw new StockExhaustedException((string) $json['message']);
        }

        if (($json['code'] ?? null) !== 200 || ($json['status'] ?? null) !== 'OK') {
            throw new RuntimeException(json_encode($json));
        }
    }

    /**
     * =====================================================
     * FAILED HANDLER
     * =====================================================
     */
    public function failed(Throwable $e): void
    {
        Log::error('[NIK] Job failed permanently', [
            'account_id' => $this->account_id,
            'nik_input_id' => $this->data_nik_input_id,
            'error' => $e->getMessage(),
        ]);
    }
}
