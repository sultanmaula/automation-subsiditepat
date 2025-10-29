<?php

namespace App\Jobs;
use App\Models\Account;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class VerifyNikTransactionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Account $account,
        public string $nik
    ) {}
    
    public function handle(): void
    {
        $bearerToken = Cache::get("merchant_api_token_{$this->account->email}");
        
        if (empty($bearerToken)) {
            throw new RuntimeException("Bearer token expired for {$this->account->email}");
        }
        
        $verifyResponse = Http::withHeaders([
            'Authorization' => 'Bearer ' . $bearerToken,
        ])->get(
            'https://api-map.my-pertamina.id/customers/v2/verify-nik',
            [
                'nationalityId' => $this->nik,
            ],
        );
        
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
            'nationalityId' => $this->nik,
            'familyIdEncrypted' => $data['familyIdEncrypted'],
            'category' => 'Rumah Tangga',
            'sourceTypeId' => 1,
            'name' => $data['name'],
            'channelinject' => $data['channelInject'],
        ];

        $transactionResponse = Http::withHeaders([
            'Authorization' => 'Bearer ' . $bearerToken,
        ])->post('https://api-map.my-pertamina.id/general/v3/transactions', $payload);
        
        dd($transactionResponse->json());
        // if ($transactionResponse->json('code') === 200 && $transactionResponse->json('status') == 'OK') {
        //     $this->account->update([
        //         'last_nik_input' => $this->nik,
        //     ]);
        // }

        // $transactionResponse->throw();

        // Log::info(
        //     'VerifyNikTransactionJob completed',
        //     [
        //         'account_id' => $this->account->id,
        //         'nik' => $this->nik,
        //         'transaction_status' => $transactionResponse->json('status') ?? null,
        //     ],
        // );
    }
}