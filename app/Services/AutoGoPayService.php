<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AutoGoPayService
{
    /**
     * Nama header yang mungkin dipakai AutoGoPay untuk mengirim signature.
     * Yang didokumentasikan adalah x-signature, sisanya jaga-jaga agar
     * probe verifikasi callback URL tidak tertolak hanya karena beda nama.
     */
    public const SIGNATURE_HEADERS = [
        'X-Signature',
        'X-Callback-Signature',
        'X-Webhook-Signature',
        'X-AutoGoPay-Signature',
        'X-Signature-256',
        'Signature',
    ];

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

    /**
     * Tanda tangani sebuah string dengan API key (HMAC-SHA256, hex lowercase).
     * Dipakai untuk membalas verification.challenge saat mendaftarkan callback URL.
     */
    public function sign(string $data): string
    {
        return hash_hmac('sha256', $data, $this->apiKey);
    }

    /**
     * Verifikasi HMAC-SHA256 dari payload mentah memakai API key sebagai secret.
     * Menerima format hex (default) maupun base64, dengan atau tanpa prefix "sha256=".
     */
    public function verifyWebhookSignature(string $payload, ?string $signature): bool
    {
        if ($this->apiKey === '' || $signature === null || trim($signature) === '') {
            return false;
        }

        $signature = trim($signature);

        if (str_contains($signature, '=') && preg_match('/^sha-?256=/i', $signature)) {
            $signature = preg_replace('/^sha-?256=/i', '', $signature);
        }

        $raw = hash_hmac('sha256', $payload, $this->apiKey, true);

        foreach ([bin2hex($raw), base64_encode($raw)] as $expected) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        // Perbandingan hex tidak case-sensitive.
        return hash_equals(bin2hex($raw), strtolower($signature));
    }
}
