<?php

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CancelDuplicateTransactionCommand extends Command
{
    protected $signature = 'merchant:cancel-duplicate
        {account : Account ID or email address}
        {customerReportId : Customer Report ID}
        {startDate : Start date (Y-m-d)}
        {endDate : End date (Y-m-d)}';

    protected $description = 'Cancel duplicate transactions for a customer, keeping only the most recent one.';

    public function handle(): int
    {
        $accountArgument = $this->argument('account');
        $customerReportId = $this->argument('customerReportId');
        $startDate = $this->argument('startDate');
        $endDate = $this->argument('endDate');

        $account = filter_var($accountArgument, FILTER_VALIDATE_INT) !== false
            ? Account::find((int) $accountArgument)
            : Account::where('email', $accountArgument)->first();

        if (!$account) {
            $this->error('Account not found.');
            return Command::FAILURE;
        }

        $token = $this->getToken($account);

        if (!$token) {
            $this->error('Failed to get bearer token.');
            return Command::FAILURE;
        }

        $this->info("Fetching transactions for customer: {$customerReportId}");

        $transactions = $this->fetchTransactions($token, $customerReportId, $startDate, $endDate);

        if (empty($transactions)) {
            $this->warn('No transactions found.');
            return Command::SUCCESS;
        }

        $notCanceledTransactions = collect($transactions)
            ->filter(fn(array $tx): bool => $tx['isCanceled'] === null)
            ->values()
            ->all();

        $this->info(sprintf('Found %d not-canceled transactions.', count($notCanceledTransactions)));

        if (count($notCanceledTransactions) <= 1) {
            $this->info('No duplicate transactions to cancel.');
            return Command::SUCCESS;
        }

        $transactionsToCancel = array_slice($notCanceledTransactions, 0, -1);

        $this->info(sprintf('Canceling %d duplicate transactions...', count($transactionsToCancel)));

        $canceledCount = 0;

        foreach ($transactionsToCancel as $transaction) {
            $transactionId = $transaction['transactionId'] ?? null;

            if (!$transactionId) {
                continue;
            }

            $this->line("Canceling transaction: {$transactionId}");

            if ($this->cancelTransaction($token, $transactionId, $account->pin)) {
                $canceledCount++;
                $this->info("Transaction {$transactionId} canceled successfully.");
            } else {
                $this->error("Failed to cancel transaction {$transactionId}.");
            }

            sleep(3);
        }

        $this->info(sprintf('Completed. Canceled %d transactions.', $canceledCount));

        return Command::SUCCESS;
    }

    protected function getToken(Account $account): ?string
    {
        $cacheKey = "merchant_api_token_{$account->email}";
        $token = Cache::get($cacheKey);

        if (!$token) {
            Artisan::call('merchant:fetch-token', [
                '--email' => $account->email,
                '--pin' => $account->pin,
            ]);

            $token = Cache::get($cacheKey);
        }

        return $token;
    }

    protected function fetchTransactions(string $token, string $customerReportId, string $startDate, string $endDate): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->get('https://api-map.my-pertamina.id/general/v1/transactions', [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'customerReportId' => $customerReportId,
        ]);

        if ($response->failed()) {
            $this->error('Failed to fetch transactions: ' . $response->body());
            return [];
        }

        $payload = $response->json();

        if (($payload['code'] ?? null) !== 200) {
            $this->error('API returned error: ' . json_encode($payload));
            return [];
        }

        return data_get($payload, 'data.data', []);
    }

    protected function cancelTransaction(string $token, string $transactionId, string $pin): bool
    {
        $url = "https://api-map.my-pertamina.id/general/v1/transactions/{$transactionId}/cancel";

        $jsonData = [
            'pin' => $pin,
            'reason' => 'double',
        ];

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                ])
                ->asJson()
                ->acceptJson()
                ->post($url, $jsonData);

            $result = $response->json();

            if (($result['code'] ?? null) !== 200 || ($result['status'] ?? null) !== 'OK') {
                $this->error('Cancel API error: ' . json_encode($result));
                return false;
            }

            return true;

        } catch (\Exception $e) {
            $this->error('Exception: ' . $e->getMessage());
            return false;
        }
    }
}
