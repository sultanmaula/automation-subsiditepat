<?php

namespace App\Jobs;

use App\Models\Account;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CancelDuplicateTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        public Account $account,
        public string $customerReportId,
        public string $startDate,
        public string $endDate,
    ) {}

    public function handle(): void
    {
        // Refresh account to ensure we have the latest data including pin
        $this->account->refresh();

        $token = $this->getToken();

        if (!$token) {
            Log::error('CancelDuplicateTransactionJob: Failed to get token', [
                'account' => $this->account->email,
                'customerReportId' => $this->customerReportId,
            ]);
            return;
        }

        // Fetch transactions for this customer
        $transactions = $this->fetchTransactions($token);

        if (empty($transactions)) {
            Log::info('CancelDuplicateTransactionJob: No transactions found', [
                'customerReportId' => $this->customerReportId,
            ]);
            return;
        }

        // Filter transactions that are not canceled (isCanceled == null)
        $notCanceledTransactions = collect($transactions)
            ->filter(fn(array $tx): bool => $tx['isCanceled'] === null)
            ->values()
            ->all();

        // If there are more than 1 not-canceled transactions, cancel all except the last one
        if (count($notCanceledTransactions) <= 1) {
            Log::info('CancelDuplicateTransactionJob: No duplicate transactions to cancel', [
                'customerReportId' => $this->customerReportId,
                'notCanceledCount' => count($notCanceledTransactions),
            ]);
            return;
        }

        Log::info('CancelDuplicateTransactionJob: Found duplicate transactions', [
            'customerReportId' => $this->customerReportId,
            'notCanceledCount' => count($notCanceledTransactions),
        ]);

        // Cancel all duplicates (all except the last one - keep the most recent)
        $transactionsToCancel = array_slice($notCanceledTransactions, 0, -1);

        foreach ($transactionsToCancel as $transaction) {
            $transactionId = $transaction['transactionId'] ?? null;

            if (!$transactionId) {
                continue;
            }

            $this->cancelTransaction($token, $transactionId);

            // Small delay between cancellations
            sleep(3); // 3 second
        }

        Log::info('CancelDuplicateTransactionJob: Completed', [
            'customerReportId' => $this->customerReportId,
            'canceledCount' => count($transactionsToCancel),
        ]);
    }

    protected function getToken(): ?string
    {
        $cacheKey = "merchant_api_token_{$this->account->email}";
        $token = Cache::get($cacheKey);

        if (!$token) {
            Artisan::call('merchant:fetch-token', [
                '--email' => $this->account->email,
                '--pin' => $this->account->pin,
            ]);

            $token = Cache::get($cacheKey);
        }

        return $token;
    }

    protected function fetchTransactions(string $token): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->get('https://api-map.my-pertamina.id/general/v1/transactions', [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'customerReportId' => $this->customerReportId,
        ]);

        if ($response->failed()) {
            Log::error('CancelDuplicateTransactionJob: Failed to fetch transactions', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return [];
        }

        $payload = $response->json();

        if (($payload['code'] ?? null) !== 200) {
            Log::error('CancelDuplicateTransactionJob: API returned error', [
                'payload' => $payload,
            ]);
            return [];
        }

        return data_get($payload, 'data.data', []);
    }

    protected function cancelTransaction(string $token, string $transactionId): bool
    {
        $url = "https://api-map.my-pertamina.id/general/v1/transactions/{$transactionId}/cancel";

        $formData = [
            'pin' => $this->account->pin,
            'reason' => 'double',
        ];

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: */*',
            'User-Agent: PostmanRuntime/7.49.0',
            'Accept-Encoding: gzip, deflate, br',
            'Connection: keep-alive',
        ];

        $response = $this->postMultipartWithCurl($url, $formData, $headers);

        if (($response['code'] ?? null) !== 200 || ($response['status'] ?? null) !== 'OK') {
            Log::error('CancelDuplicateTransactionJob: Cancel API returned error', [
                'transactionId' => $transactionId,
                'payload' => $response,
            ]);
            return false;
        }

        Log::info('CancelDuplicateTransactionJob: Transaction canceled successfully', [
            'transactionId' => $transactionId,
        ]);

        return true;
    }

    protected function postMultipartWithCurl(string $url, array $fields, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false) {
            Log::error('CancelDuplicateTransactionJob: cURL error', [
                'error' => $error,
                'url' => $url,
            ]);
            return ['code' => 0, 'message' => 'cURL error: ' . $error];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            Log::error('CancelDuplicateTransactionJob: Unexpected response', [
                'status' => $status,
                'raw' => $raw,
            ]);
            return ['code' => $status, 'message' => 'Unexpected response'];
        }

        return $decoded;
    }
}
