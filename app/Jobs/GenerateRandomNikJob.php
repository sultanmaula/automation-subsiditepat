<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\DataMasterDocument;
use App\Models\DataNikInput;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class GenerateRandomNikJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $accountId,
        public int $documentId,
        public string $nik,
    ) {
    }

    public function handle(): void
    {
        $account = Account::find($this->accountId);
        $document = DataMasterDocument::find($this->documentId);

        if (! $account || ! $document) {
            return;
        }

        $token = $this->getBearerToken($account);

        if (! $token) {
            return;
        }

        $nik = preg_replace('/\D+/', '', (string) $this->nik);

        if ($nik === '') {
            return;
        }

        if (DataNikInput::where('nik', $nik)->exists()) {
            return;
        }

        $responseData = $this->verifyNik($token, $nik, $account);

        $this->sleepThrottle();

        if (! $responseData) {
            return;
        }

        $order = ((int) (DataNikInput::where('data_master_document_id', $document->id)->max('order') ?? 0) + 1);

        $record = DataNikInput::updateOrCreate(
            ['nik' => $nik],
            [
                'name' => $responseData['name'] ?? 'Unknown',
                'address' => $responseData['address'] ?? null,
                'data_master_document_id' => $document->id,
                'order' => $order,
            ]
        );

        $this->appendToDocument($document, $record->nik, $record->name, $record->address);

        $this->updateDocumentSize($document);
    }

    protected function verifyNik(string $bearerToken, string $nik, Account $account): ?array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $bearerToken,
        ])->get('https://api-map.my-pertamina.id/customers/v2/verify-nik', [
            'nationalityId' => $nik,
        ]);

        if ($response->status() === 401) {
            Cache::forget("merchant_api_token_{$account->email}");

            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $payload = $response->json();

        if (($payload['code'] ?? null) !== 200) {
            return null;
        }

        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            return null;
        }

        return $data;
    }

    protected function getBearerToken(Account $account): ?string
    {
        if (! Cache::get("merchant_api_token_{$account->email}")) {
            Artisan::call('merchant:fetch-token', [
                '--email' => $account->email,
                '--pin' => $account->pin,
            ]);
        }

        return Cache::get("merchant_api_token_{$account->email}");
    }

    protected function appendToDocument(DataMasterDocument $document, string $nik, ?string $name, ?string $address): void
    {
        if (! $document->path) {
            return;
        }

        $disk = $document->disk ?? config('filesystems.default', 'local');
        $storage = Storage::disk($disk);

        if (! $storage->exists($document->path)) {
            $storage->put($document->path, "nik,name,address\n");
        }

        $line = implode(',', [
            $nik,
            str_replace(',', ' ', $name ?? ''),
            str_replace(',', ' ', (string) $address),
        ]);

        $storage->append($document->path, $line);
    }

    protected function updateDocumentSize(DataMasterDocument $document): void
    {
        if (! $document->path) {
            return;
        }

        $disk = $document->disk ?? config('filesystems.default', 'local');

        if (! Storage::disk($disk)->exists($document->path)) {
            return;
        }

        $document->update([
            'size' => Storage::disk($disk)->size($document->path),
        ]);
    }

    protected function sleepThrottle(): void
    {
        usleep(6000000);
    }
}
