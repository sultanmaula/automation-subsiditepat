<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\DataNikInput;
use App\Models\NikInputHistory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
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
    ) {}

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
            return; // job dianggap sukses
        }

        /** =========================
         * 3. LIMIT BULANAN (4x / NIK)
         * ========================= */
        $monthlyCount = NikInputHistory::where('account_id', $this->account_id)
            ->where('nik', $nikInput->nik)
            ->where('input_month', $month)
            ->count();

        if ($monthlyCount >= 4) {
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
         * 5. PASTIKAN TOKEN ADA
         * ========================= */
        if (! Cache::get("merchant_api_token_{$account->email}")) {
            Artisan::call('merchant:fetch-token', [
                '--email' => $account->email,
                '--pin'   => $account->pin,
            ]);
        }

        $bearerToken = Cache::get("merchant_api_token_{$account->email}");
        if (! $bearerToken) {
            throw new RuntimeException('Merchant token not available');
        }

        /** =========================
         * 6. CALL API PERTAMINA
         * ========================= */
        $verifyData = $this->verifyNik($bearerToken, $nikInput->nik);
        $this->submitTransaction($bearerToken, $nikInput->nik, $verifyData, $account);

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
    protected function verifyNik(string $bearerToken, string $nik): array
    {
        $res = Http::withHeaders([
            'Authorization' => 'Bearer ' . $bearerToken,
        ])->get(
            'https://api-map.my-pertamina.id/customers/v2/verify-nik',
            ['nationalityId' => $nik]
        );

        if ($res->failed()) {
            throw new RuntimeException('Verify-NIK failed');
        }

        $data = $res->json('data');

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
