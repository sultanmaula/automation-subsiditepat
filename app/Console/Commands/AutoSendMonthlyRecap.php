<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AutoSendMonthlyRecap extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'account:monthly-recap';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Kirim rekapan bulanan total transaksi semua accounts via WhatsApp';

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
        $overLimitCustomers = [];

        $startDate = now()->startOfMonth()->format('Y-m-d');
        $endDate = now()->format('Y-m-d');

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
                    'overLimitCount' => 0,
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
                'startDate' => $startDate,
                'endDate'   => $endDate,
            ]);

            $totalQuantity = 0;
            if ($resTransaction->successful() && ($resTransaction['code'] ?? null) == 200) {
                $totalQuantity = $resTransaction['data']['totalQuantity'] ?? 0;
            }

            $totalAllQuantity += $totalQuantity;

            // Fetch report bulanan untuk deteksi customer > 4x input dalam sebulan
            $overLimitCount = 0;
            $overLimitGrouped = []; // total => jumlah orang

            $resReport = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
            ])->get('https://api-map.my-pertamina.id/general/v3/transactions/report', [
                'search'    => '',
                'sort'      => 'latest',
                'startDate' => $startDate,
                'endDate'   => $endDate,
            ]);

            if ($resReport->successful() && ($resReport['success'] ?? false)) {
                $customers = $resReport['data']['customersReport'] ?? [];

                foreach ($customers as $customer) {
                    if (($customer['total'] ?? 0) > 4) {
                        $total = $customer['total'];
                        $overLimitGrouped[$total] = ($overLimitGrouped[$total] ?? 0) + 1;
                        $overLimitCount++;
                    }
                }
            }

            if (!empty($overLimitGrouped)) {
                krsort($overLimitGrouped);
                $overLimitCustomers[$storeName] = $overLimitGrouped;
            }

            $recapItems[] = [
                'email'          => $account->email,
                'storeName'      => $storeName,
                'overLimitCount' => $overLimitCount,
                'totalQuantity'  => $totalQuantity,
                'status'         => $overLimitCount > 0 ? 'Belum Tuntas' : 'Tuntas',
            ];
        }

        $phones = [
            '6285231731037', // Sultan
            // '6285745219894', // Mama Luluk
            // '6285704231663', // Istri
        ];

        $message = WhatsAppService::monthlyRecap($recapItems, $totalAllQuantity, $startDate, $endDate, $overLimitCustomers);

        foreach ($phones as $phone) {
            WhatsAppService::send($phone, $message);
            $this->info('Rekapan bulanan berhasil dikirim ke ' . $phone);
        }
    }
}
