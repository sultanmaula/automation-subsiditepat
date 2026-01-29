<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\NikInputHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class VerifyNikTransactionCommand extends Command
{
    protected $signature = 'merchant:verify-nik
        {account : Account ID or email address}
        {nik : NIK number to verify and submit}
        {--document-id= : Data Master Document ID (optional)}
        {--nik-input-id= : Data NIK Input ID (optional)}';

    protected $description = 'Verify a NIK and submit a transaction using the merchant API.';

    public function handle(): int
    {
        $accountArgument = $this->argument('account');
        $nik = preg_replace('/\D+/', '', (string) $this->argument('nik'));

        if ($nik === '') {
            $this->error('NIK must contain digits.');

            return Command::FAILURE;
        }
        $account = filter_var($accountArgument, FILTER_VALIDATE_INT) !== false
            ? Account::find((int) $accountArgument)
            : Account::where('email', $accountArgument)->first();

        if (!$account) {
            $this->error('Account not found.');

            return Command::FAILURE;
        }

        /**
         * Add validation Daily Limit
         */
        $dailyLimit = 200;
        $inputToday = $account->nikInputHistories()->where('input_date', now()->toDateString())->count();
        if ($inputToday >= $dailyLimit) {
            return Command::SUCCESS;
        }

        if (!Cache::get("merchant_api_token_{$account->email}")) {
            Artisan::call('merchant:fetch-token', [
                '--email' => $account->email,
                '--pin'   => $account->pin,
            ]);
        }

        $bearerToken = Cache::get("merchant_api_token_{$account->email}");

        $documentId = $this->option('document-id') ? (int) $this->option('document-id') : null;
        $nikInputId = $this->option('nik-input-id') ? (int) $this->option('nik-input-id') : null;

        try {
            $verifyData = $this->verifyNik($bearerToken, $nik);
            $transactionResponse = $this->submitTransaction($bearerToken, $nik, $verifyData, $account, $documentId, $nikInputId);

            $this->info(sprintf(
                'Transaction for NIK %s succeeded with status %s.',
                $nik,
                $transactionResponse['status'] ?? 'UNKNOWN'
            ));

            usleep(5000000);

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }
    }

    protected function verifyNik(string $bearerToken, string $nik): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $bearerToken,
        ])->get('https://api-map.my-pertamina.id/customers/v2/verify-nik', [
            'nationalityId' => $nik,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Verify-NIK request failed: ' . $response->body());
        }

        $data = $response->json('data');

        if (! is_array($data)) {
            throw new RuntimeException('Invalid verify-nik response: missing data payload.');
        }

        foreach (['token', 'familyIdEncrypted', 'name'] as $requiredKey) {
            if (! array_key_exists($requiredKey, $data) || blank($data[$requiredKey])) {
                throw new RuntimeException("Invalid verify-nik response: missing {$requiredKey}.");
            }
        }

        usleep(3000000);

        return $data;
    }

    protected function submitTransaction(string $bearerToken, string $nik, array $verifyData, Account $account, ?int $documentId = null, ?int $nikInputId = null): array
    {
        $formData = [
            'quantity' => '1',
            'token' => (string) $verifyData['token'],
            'nationalityId' => $nik,
            'familyIdEncrypted' => (string) $verifyData['familyIdEncrypted'],
            'category' => $verifyData['customerTypes'][0]['name'] ?? 'Rumah Tangga',
            'sourceTypeId' => '1',
            'name' => (string) $verifyData['name'],
            'channelinject' => $verifyData['channelInject'] ?? 'tnp2k',
        ];

        $response = $this->postMultipartWithCurl('https://api-map.my-pertamina.id/general/v3/transactions', $formData, [
            'Authorization: Bearer ' . $bearerToken,
            'Accept: */*',
            'User-Agent: PostmanRuntime/7.49.0',
            'Accept-Encoding: gzip, deflate, br',
            'Connection: keep-alive',
        ]);

        if (($response['code'] ?? null) !== 200 || ($response['status'] ?? null) !== 'OK') {
            throw new RuntimeException(json_encode($response));
        }

        // Insert into NikInputHistory if document-based input
        if ($documentId !== null && $nikInputId !== null) {
            $account->update([
                'last_nik_input' => $nik,
                'last_update_api' => now(),
            ]);
            
            NikInputHistory::create([
                'account_id' => $account->id,
                'data_master_document_id' => $documentId,
                'data_nik_input_id' => $nikInputId,
                'nik' => $nik,
                'input_date' => now()->toDateString(),
                'input_month' => now()->format('Y-m'),
            ]);
        }

        usleep(3000000);

        return $response;
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
            throw new RuntimeException('cURL error: ' . $error);
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('Unexpected response (%s): %s', $status, $raw));
        }

        return $decoded;
    }
}
