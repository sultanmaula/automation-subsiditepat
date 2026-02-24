<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AutoSendDailyRecap extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'account:daily-recap';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Kirim rekapan harian total transaksi semua accounts via WhatsApp';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $accounts = Account::all();

        if ($accounts->isEmpty()) {
            $this->warn('Tidak ada account yang terdaftar.');
            return;
        }

        $recapItems = [];
        $totalAllQuantity = 0;

        foreach ($accounts as $account) {
            if (!Cache::get("merchant_api_token_{$account->email}")) {
                Artisan::call('merchant:fetch-token', [
                    '--email' => $account->email,
                    '--pin'   => $account->pin,
                ]);
            }

            $token = Cache::get("merchant_api_token_{$account->email}");

            if (!$token) {
                $recapItems[] = [
                    'email'          => $account->email,
                    'storeName'      => '-',
                    'stockAvailable' => 0,
                    'totalQuantity'  => 0,
                    'status'         => 'Token gagal',
                ];
                continue;
            }

            $resProduct = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
            ])->get('https://api-map.my-pertamina.id/general/products/v1/products/user');

            $storeName = '-';
            $stockAvailable = 0;

            if ($resProduct->successful() && $resProduct['code'] == 200 && $resProduct['status'] == 'OK') {
                $storeName = $resProduct['data']['storeName'] ?? '-';
                $stockAvailable = $resProduct['data']['stockAvailable'] ?? 0;
            }

            $resTransaction = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
            ])->get('https://api-map.my-pertamina.id/general/transactions/v1/summary', [
                'startDate' => now()->format('Y-m-d'),
                'endDate'   => now()->format('Y-m-d'),
            ]);

            $totalQuantity = 0;
            if ($resTransaction->successful() && ($resTransaction['code'] ?? null) == 200) {
                $totalQuantity = $resTransaction['data']['totalQuantity'] ?? 0;
            }

            $totalAllQuantity += $totalQuantity;

            $recapItems[] = [
                'email'          => $account->email,
                'storeName'      => $storeName,
                'stockAvailable' => $stockAvailable,
                'totalQuantity'  => $totalQuantity,
                'status'         => ($stockAvailable > 0 && $totalQuantity < 200) ? 'Belum Tuntas' : 'Tuntas',
            ];
        }

        $phones = [
            '6285231731037', // Sultan
            '6285745219894', // Mama Luluk
            '6285704231663', // Istri
        ];

        $message = WhatsAppService::dailyRecap($recapItems, $totalAllQuantity);

        foreach ($phones as $phone) {
            WhatsAppService::send($phone, $message);
            $this->info('Rekapan harian berhasil dikirim ke ' . $phone);
        }
    }
}
