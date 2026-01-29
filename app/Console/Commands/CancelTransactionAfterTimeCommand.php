<?php

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CancelTransactionAfterTimeCommand extends Command
{
    protected $signature = 'merchant:cancel-after-time
        {account? : Account ID or email address (optional, if not provided will process all accounts)}
        {--date= : The date to check transactions (format: Y-m-d, default: today)}
        {--time= : The cutoff time (format: H:i, e.g., 17:00). Transactions after this time will be canceled}
        {--dry-run : Run without actually canceling transactions}';

    protected $description = 'Cancel transactions that were input after a specified time on a given date.';

    protected int $canceledCount = 0;
    protected int $skippedCount = 0;

    public function handle(): int
    {
        $accountArgument = $this->argument('account');
        $date = $this->option('date') ?? now()->toDateString();
        $cutoffTime = $this->option('time');
        $isDryRun = $this->option('dry-run');

        if (!$cutoffTime) {
            $this->error('The --time option is required. Example: --time=17:00');
            return Command::FAILURE;
        }

        // Validate date format
        try {
            $targetDate = Carbon::parse($date);
        } catch (\Exception $e) {
            $this->error('Invalid date format. Please use Y-m-d format (e.g., 2026-01-23)');
            return Command::FAILURE;
        }

        // Validate time format
        if (!preg_match('/^\d{2}:\d{2}$/', $cutoffTime)) {
            $this->error('Invalid time format. Please use H:i format (e.g., 17:00)');
            return Command::FAILURE;
        }

        $cutoffDateTime = Carbon::parse("{$date} {$cutoffTime}:00");

        $this->info(sprintf(
            'Canceling transactions after %s on %s %s',
            $cutoffTime,
            $targetDate->format('d M Y'),
            $isDryRun ? '[DRY RUN]' : ''
        ));
        $this->newLine();

        // Get accounts to process
        if ($accountArgument) {
            $account = filter_var($accountArgument, FILTER_VALIDATE_INT) !== false
                ? Account::find((int) $accountArgument)
                : Account::where('email', $accountArgument)->first();

            if (!$account) {
                $this->error('Account not found.');
                return Command::FAILURE;
            }

            $accounts = collect([$account]);
        } else {
            $accounts = Account::all();
            $this->info("Processing all {$accounts->count()} accounts...");
        }

        foreach ($accounts as $account) {
            $this->processAccount($account, $date, $cutoffDateTime, $isDryRun);
        }

        $this->newLine();
        $this->info('=== Summary ===');
        $this->info("Canceled: {$this->canceledCount} transactions");
        $this->info("Skipped: {$this->skippedCount} transactions");

        return Command::SUCCESS;
    }

    protected function processAccount(Account $account, string $date, Carbon $cutoffDateTime, bool $isDryRun): void
    {
        // Refresh account to ensure we have the latest data
        $account->refresh();

        $this->info("Processing account: {$account->email}");

        // Check if PIN exists
        if (empty($account->pin)) {
            $this->warn("  PIN is empty for {$account->email}, skipping...");
            $this->warn("  Please set PIN for this account in the database.");
            return;
        }

        $this->line("  PIN found: " . str_repeat('*', strlen($account->pin) - 2) . substr($account->pin, -2));

        $token = $this->getToken($account);

        if (!$token) {
            $this->warn("  Failed to get token for {$account->email}, skipping...");
            return;
        }

        // Fetch all transactions for the date
        $transactions = $this->fetchAllTransactions($token, $date);

        if (empty($transactions)) {
            $this->line("  No transactions found for {$date}");
            return;
        }

        $this->line("  Found " . count($transactions) . " transactions");

        // Filter transactions that are after cutoff time and not already canceled
        $transactionsToCancel = collect($transactions)
            ->filter(function (array $tx) use ($cutoffDateTime): bool {
                // Skip already canceled transactions
                if ($tx['isCanceled'] !== null) {
                    return false;
                }

                // Parse transaction time
                $txDate = $tx['date'] ?? null;
                $txTime = $tx['time'] ?? null;

                if (!$txDate || !$txTime) {
                    return false;
                }

                try {
                    // Convert date format "23 Jan 2026" to Carbon
                    $txDateTime = Carbon::parse("{$txDate} {$txTime}");
                    return $txDateTime->greaterThan($cutoffDateTime);
                } catch (\Exception $e) {
                    return false;
                }
            })
            ->values()
            ->all();

        if (empty($transactionsToCancel)) {
            $this->line("  No transactions to cancel after cutoff time");
            return;
        }

        $this->warn("  Found " . count($transactionsToCancel) . " transactions after cutoff time");

        foreach ($transactionsToCancel as $transaction) {
            $transactionId = $transaction['transactionId'] ?? null;
            $customerName = $transaction['customerName'] ?? 'Unknown';
            $txTime = $transaction['time'] ?? '-';
            $txDate = $transaction['date'] ?? '-';

            if (!$transactionId) {
                $this->skippedCount++;
                continue;
            }

            if ($isDryRun) {
                $this->line("    [DRY RUN] Would cancel: {$customerName} - {$txDate} {$txTime}");
                $this->canceledCount++;
            } else {
                $this->line("    Canceling: {$customerName} - {$txDate} {$txTime} (ID: {$transactionId})");
                $result = $this->cancelTransaction($token, $transactionId, $account->pin);

                if ($result === true) {
                    $this->info("    Canceled successfully");
                    $this->canceledCount++;
                } else {
                    $errorMessage = is_array($result) ? ($result['message'] ?? json_encode($result)) : 'Unknown error';
                    $errorCode = is_array($result) ? ($result['code'] ?? 'N/A') : 'N/A';
                    $this->error("    Failed to cancel - Code: {$errorCode}, Message: {$errorMessage}");
                    $this->skippedCount++;
                }

                // Delay between cancellations
                sleep(3);
            }
        }
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

    protected function fetchAllTransactions(string $token, string $date): array
    {
        try {
            $response = Http::timeout(30)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
            ])->get('https://api-map.my-pertamina.id/general/v3/transactions/report', [
                'startDate' => $date,
                'endDate' => $date,
                'sort' => 'latest',
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('CancelTransactionAfterTimeCommand: Connection error fetching report', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $payload = $response->json();

        if (($payload['code'] ?? null) !== 200 || ($payload['status'] ?? null) !== 'OK') {
            return [];
        }

        // Get all customers from the report
        $customersReport = data_get($payload, 'data.customersReport', []);
        $allTransactions = [];

        foreach ($customersReport as $customer) {
            $customerReportId = $customer['customerReportId'] ?? null;

            if (!$customerReportId) {
                continue;
            }

            // Fetch detailed transactions for this customer
            $transactions = $this->fetchCustomerTransactions($token, $date, $customerReportId);
            $allTransactions = array_merge($allTransactions, $transactions);
        }

        return $allTransactions;
    }

    protected function fetchCustomerTransactions(string $token, string $date, string $customerReportId): array
    {
        try {
            $response = Http::timeout(30)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
            ])->get('https://api-map.my-pertamina.id/general/v1/transactions', [
                'startDate' => $date,
                'endDate' => $date,
                'customerReportId' => $customerReportId,
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $payload = $response->json();

        if (($payload['code'] ?? null) !== 200 || ($payload['status'] ?? null) !== 'OK') {
            return [];
        }

        return data_get($payload, 'data.data', []);
    }

    protected function cancelTransaction(string $token, string $transactionId, string $pin): bool|array
    {
        $url = "https://api-map.my-pertamina.id/general/v1/transactions/{$transactionId}/cancel";

        $jsonData = [
            'pin' => $pin,
            'reason' => 'double',
        ];

        Log::info('CancelTransactionAfterTimeCommand: Sending cancel request', [
            'url' => $url,
            'jsonData' => $jsonData,
        ]);

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post($url, $jsonData);

            $result = $response->json();

            Log::info('CancelTransactionAfterTimeCommand: API Response', [
                'status' => $response->status(),
                'response' => $result,
            ]);

            if (($result['code'] ?? null) !== 200 || ($result['status'] ?? null) !== 'OK') {
                Log::error('CancelTransactionAfterTimeCommand: Cancel API returned error', [
                    'transactionId' => $transactionId,
                    'payload' => $result,
                ]);
                return $result;
            }

            Log::info('CancelTransactionAfterTimeCommand: Transaction canceled successfully', [
                'transactionId' => $transactionId,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('CancelTransactionAfterTimeCommand: Exception', [
                'error' => $e->getMessage(),
            ]);
            return ['code' => 0, 'message' => $e->getMessage()];
        }
    }
}
