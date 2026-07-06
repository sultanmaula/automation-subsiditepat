<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\NikInputHistory;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SendWeeklyRecapCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'account:weekly-recap';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Kirim rekapan mingguan stok bulanan dan NIK input > 4x via WhatsApp';

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

        // Rentang waktu: dari awal bulan berjalan sampai hari ini
        $startDate = Carbon::now()->startOfMonth()->format('Y-m-d');
        $endDate = Carbon::now()->format('Y-m-d');

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
                    'overLimitCount' => 0,
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

            // Panggil API summary transaksi dengan retry karena API MyPertamina terkadang 500
            $totalQuantity = 0;
            $resTransaction = null;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $resTransaction = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                ])->get('https://api-map.my-pertamina.id/general/transactions/v1/summary', [
                    'startDate' => $startDate,
                    'endDate'   => $endDate,
                ]);

                if ($resTransaction->successful() && ($resTransaction['code'] ?? null) == 200) {
                    $totalQuantity = $resTransaction['data']['totalQuantity'] ?? 0;
                    break;
                }
                sleep(1);
            }

            $totalAllQuantity += $totalQuantity;

            // Fetch report transaksi myPertamina untuk deteksi customer > 4x input dalam sebulan terakhir
            $overLimitCount = 0;
            $overLimitGrouped = []; // total => jumlah orang

            $resReport = null;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $resReport = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                ])->get('https://api-map.my-pertamina.id/general/v3/transactions/report', [
                    'search'    => '',
                    'sort'      => 'latest',
                    'startDate' => $startDate,
                    'endDate'   => $endDate,
                ]);

                if ($resReport->successful() && ($resReport['success'] ?? false)) {
                    break;
                }
                sleep(1);
            }

            if ($resReport && $resReport->successful() && ($resReport['success'] ?? false)) {
                $customers = $resReport['data']['customersReport'] ?? [];

                foreach ($customers as $customer) {
                    $total = $customer['total'] ?? 0;
                    if ($total > 4) {
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
                'stockAvailable' => $stockAvailable,
                'totalQuantity'  => $totalQuantity,
                'overLimitCount' => $overLimitCount,
                'status'         => $stockAvailable > 0 ? 'Belum Tuntas' : 'Tuntas',
            ];
        }

        $phones = [
            '6285231731037', // Sultan
        ];

        // Format pesan mingguan
        $message = $this->buildWeeklyRecapMessage($recapItems, $totalAllQuantity, $startDate, $endDate, $overLimitCustomers);

        foreach ($phones as $phone) {
            WhatsAppService::send($phone, $message);
            $this->info('Rekapan mingguan berhasil dikirim ke ' . $phone);
        }
    }

    /**
     * Build the weekly recap message format.
     */
    protected function buildWeeklyRecapMessage(array $recapItems, int $totalAllQuantity, string $startDate, string $endDate, array $overLimitCustomers = [])
    {
        $periodStart = Carbon::parse($startDate)->format('d M');
        $periodEnd = Carbon::parse($endDate)->format('d M Y');

        $message = "📊 *Rekap Mingguan* ({$periodStart} – {$periodEnd})\n\n";

        foreach ($recapItems as $i => $item) {
            $no = $i + 1;
            $dobelText = $item['overLimitCount'] > 0
                ? "{$item['overLimitCount']} orang >4x"
                : 'aman';
            $message .= "{$no}. *{$item['storeName']}* — {$item['status']}\n"
                    . "    Input *{$item['totalQuantity']}* | Stok {$item['stockAvailable']} | {$dobelText}\n";
        }

        $message .= "\n📦 Total input bulan ini: *{$totalAllQuantity}* tabung ("
                . count($recapItems) . " akun)\n";

        if (!empty($overLimitCustomers)) {
            $message .= "\n🚨 *NIK terinput >4x bulan ini:*\n";

            foreach ($overLimitCustomers as $storeName => $grouped) {
                $details = [];
                foreach ($grouped as $total => $count) {
                    $details[] = "{$total}x = {$count} orang";
                }
                $message .= "• {$storeName}: " . implode(', ', $details) . "\n";
            }
        } else {
            $message .= "\n✅ Tidak ada NIK terinput >4x bulan ini\n";
        }

        $message .= "\n🕐 " . Carbon::now()->format('d M Y H:i') . " WIB";

        return $message;
    }
}
