<?php

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class VerifyNikTransaction extends Command
{
    protected $signature = 'merchant:verify-nik {email : Email akun merchant} {nik : NIK yang akan diverifikasi}';
    protected $description = 'Verifikasi NIK dan submit transaksi ke Subsidi Tepat LPG API.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $nik = (string) $this->argument('nik');

        $account = Account::where('email', $email)->first();

        if (! $account) {
            $this->error("Account with email {$email} not found.");
            return Command::FAILURE;
        }

        try {
            $bearerToken = $this->getBearerToken($account);

            $verifyResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $bearerToken,
                'Accept' => 'application/json',
            ])->get('https://api-map.my-pertamina.id/customers/v2/verify-nik', [
                'nationalityId' => $nik,
            ]);

            $verifyResponse->throw();

            $data = $verifyResponse->json('data');

            if (! is_array($data)) {
                throw new RuntimeException('Invalid verify-nik response: missing data payload.');
            }

            foreach (['token', 'familyIdEncrypted', 'name'] as $requiredKey) {
                if (! array_key_exists($requiredKey, $data) || $data[$requiredKey] === null || $data[$requiredKey] === '') {
                    throw new RuntimeException("Invalid verify-nik response: missing {$requiredKey}.");
                }
            }

            $payload = [
                'quantity' => 1,
                'token' => $data['token'],
                'nationalityId' => $nik,
                'familyIdEncrypted' => $data['familyIdEncrypted'],
                'category' => 'Rumah Tangga',
                'sourceTypeId' => 1,
                'name' => $data['name'],
                'channelInject' => 'tnp2k',
            ];

            $transactionResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $bearerToken,
                'Accept' => 'application/json',
            ])->post('https://api-map.my-pertamina.id/general/v3/transactions', $payload);

            $transactionResponse->throw();

            $this->info('Transaction submitted successfully.');

            Log::info('merchant:verify-nik completed', [
                'account_id' => $account->id,
                'email' => $email,
                'nik' => $nik,
                'transaction_status' => $transactionResponse->json('status'),
            ]);

            return Command::SUCCESS;
        } catch (RequestException $exception) {
            $message = $exception->response ? $exception->response->body() : $exception->getMessage();
            $this->error('HTTP error: ' . $message);
            Log::error('merchant:verify-nik failed', [
                'account_id' => $account->id,
                'email' => $email,
                'nik' => $nik,
                'error' => $message,
            ]);

            return Command::FAILURE;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            Log::error('merchant:verify-nik failed', [
                'account_id' => $account->id,
                'email' => $email,
                'nik' => $nik,
                'error' => $exception->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }

    private function getBearerToken(Account $account): string
    {
        $cacheKey = "merchant_api_token_{$account->email}";
        $token = Cache::get($cacheKey);

        if (empty($token)) {
            throw new RuntimeException("Bearer token expired or missing for {$account->email}. Please run merchant:fetch-token first.");
        }

        return (string) $token;
    }
}

