<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\DataNikInput;
use App\Services\TelegramService;
use App\Services\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AutoRetrieveDataAccounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'account:auto-retrieve {account}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $account = Account::findOrFail($this->argument('account'));

        if (!Cache::get("merchant_api_token_{$account->email}"))
            Artisan::call('merchant:fetch-token', [
                '--email' => $account->email,
                '--pin'   => $account->pin,
            ]);

        if ($token = Cache::get("merchant_api_token_{$account->email}")) {
            $res = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
            ])->get('https://api-map.my-pertamina.id/general/products/v1/products/user');

            if ($res->successful() && $res['code'] == 200 && $res['status'] == 'OK') {
                $data = $res['data'];

                $stockAvailable = $data['stockAvailable'];
                if ($stockAvailable > 0) {
                    WhatsAppService::send(
                        '6285231731037',
                        WhatsAppService::reminderStock($account->email, $stockAvailable)
                    );

                    $documentId = 5;
                    $lastNikValue = $account->last_nik_input;

                    if (!$lastNikValue) {
                        $this->warn("Account {$account->email}: Belum ada riwayat NIK terakhir, skip.");
                        return;
                    }

                    $lastNikRecord = DataNikInput::query()
                        ->where('data_master_document_id', $documentId)
                        ->where('nik', $lastNikValue)
                        ->first();

                    if (!$lastNikRecord) {
                        $this->warn("Account {$account->email}: NIK terakhir tidak ditemukan pada dokumen, skip.");
                        return;
                    }

                    $startingOrder = ((int) $lastNikRecord->order) + 1;

                    $nikInputs = DataNikInput::query()
                        ->where('data_master_document_id', $documentId)
                        ->where('order', '>=', $startingOrder)
                        ->orderBy('order')
                        ->get(['id', 'nik']);

                    if ($nikInputs->isEmpty()) {
                        $this->info("Account {$account->email}: Semua NIK pada dokumen telah diproses.");
                        return;
                    }

                    foreach ($nikInputs as $nikInput) {
                        $exitCode = Artisan::call('merchant:verify-nik', [
                            'account' => $account->email,
                            'nik' => $nikInput->nik,
                            '--document-id' => $documentId,
                            '--nik-input-id' => $nikInput->id,
                        ]);

                        if ($exitCode !== Command::SUCCESS) {
                            $output = trim(Artisan::output());
                            $decoded = json_decode($output);

                            if ($decoded?->message === 'Transaksi melebihi stok yang dapat dijual' && $decoded?->code === 400) {
                                $this->info("Account {$account->email}: Stok hari ini sudah ter-input semua.");
                                break;
                            }

                            if (Str::startsWith($output, "Verify-NIK request failed") || Str::startsWith($output, "Invalid verify-nik response") || ($decoded && ($decoded?->code ?? 0) >= 400)) {
                                usleep(3000000);
                                continue;
                            }

                            $this->error("Account {$account->email}: Gagal memproses NIK {$nikInput->nik}");
                            break;
                        }
                        usleep(3000000);
                    }
                }
            }
        }

        return $this->info('Success execution command!');
    }
}
