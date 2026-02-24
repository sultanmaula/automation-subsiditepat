<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AutoSendReminder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'account:auto-send-reminder {account}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto send reminder to user if stock is available';

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
                $responseTransaction = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                ])->get('https://api-map.my-pertamina.id/general/transactions/v1/summary?startDate='.now()->format('Y-m-d').'&endDate='.now()->format('Y-m-d'));
                
                $inputToday = $responseTransaction['data']['totalQuantity'];

                $data = $res['data'];
                $stockAvailable = $data['stockAvailable'];
                $storeName = $data['storeName'];
                if ($stockAvailable > 0 && $inputToday < 200) {
                    WhatsAppService::send(
                        $account->phone ?? '6285231731037',
                        WhatsAppService::reminderStock($storeName, $account->email, $stockAvailable, $inputToday)
                    );
                }
            }
        }
    }
}
