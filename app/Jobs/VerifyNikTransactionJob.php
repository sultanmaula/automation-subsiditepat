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
use RuntimeException;

class VerifyNikTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $nik;

    public function __construct(protected Account $account, string $nik)
    {
        $this->nik = preg_replace('/\D+/', '', $nik);
    }

    public function handle(): array
    {
        if ($this->nik === '') {
            throw new RuntimeException('NIK must contain digits.');
        }

        if (! Cache::get("merchant_api_token_{$this->account->email}")) {
            Artisan::call('merchant:fetch-token', [
                '--email' => $this->account->email,
                '--pin' => $this->account->pin,
            ]);
        }

        $bearerToken = Cache::get("merchant_api_token_{$this->account->email}");

        if (! $bearerToken) {
            throw new RuntimeException('Unable to acquire bearer token.');
        }

        $verifyData = $this->verifyNik($bearerToken, $this->nik);
        $transactionResponse = $this->submitTransaction($bearerToken, $this->nik, $verifyData, $this->account);

        return $transactionResponse;
    }

    protected function verifyNik(string $bearerToken, string $nik): array
    {
        // Endpoint customer-service — lihat alasannya di ProcessNikJob::verifyNik().
        // Token dari /customers/v2/verify-nik ditolak 406 oleh submit transaksi.
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $bearerToken,
        ])->get('https://api-map.my-pertamina.id/general/customer-service/v1/verify-nik', [
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

    protected function submitTransaction(string $bearerToken, string $nik, array $verifyData, Account $account): array
    {
        $formData = [
            'quantity' => '1',
            'token' => (string) $verifyData['token'],
            'nationalityId' => $nik,
            'familyIdEncrypted' => (string) $verifyData['familyIdEncrypted'],
            'category' => $verifyData['customerTypes'][0]['name'] ?? 'Rumah Tangga',
            'sourceTypeId' => '1',
            'name' => (string) $verifyData['name'],
            'channelInject' => $verifyData['channelInject'] ?? 'tnp2k',
        ];

        $response = $this->postMultipartWithCurl('https://api-map.my-pertamina.id/general/v3/transactions', $formData, [
            'Authorization: Bearer ' . $bearerToken,
            'Accept: */*',
            'User-Agent: PostmanRuntime/7.49.0',
            'Accept-Encoding: gzip, deflate, br',
            'Connection: keep-alive',
        ]);

        $account->update([
            'last_nik_input' => $nik,
            'last_update_api' => now(),
        ]);

        if (($response['code'] ?? null) !== 200 || ($response['status'] ?? null) !== 'OK') {
            throw new RuntimeException(json_encode($response));
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
