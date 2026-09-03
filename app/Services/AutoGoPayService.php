<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AutoGoPayService
{
    private string $baseUrl = 'https://v1-gateway.autogopay.site';
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.autogopay.api_key', '');
    }

    public function checkStatus(string $transactionId): ?string
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(10)
                ->post("{$this->baseUrl}/qris/status", [
                    'transaction_id' => $transactionId,
                ]);

            if ($response->successful()) {
                return $response->json('data.transaction_status');
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('AutoGoPay checkStatus exception', ['message' => $e->getMessage()]);
            return null;
        }
    }

    public function generateQris(int $amount): ?array
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(15)
                ->post("{$this->baseUrl}/qris/generate", [
                    'amount' => $amount,
                ]);

            if ($response->successful() && $response->json('success')) {
                return $response->json('data');
            }

            Log::error('AutoGoPay generateQris failed', [
                'status' => $response->status(),
                'body'   => $response->json(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('AutoGoPay generateQris exception', ['message' => $e->getMessage()]);
            return null;
        }
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $expected = hash_hmac('sha256', $payload, $this->apiKey);
        return hash_equals($expected, $signature);
    }
}
