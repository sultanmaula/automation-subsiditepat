<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class SendScreenshotAllCommand extends Command
{
    protected $signature = 'merchant:send-screenshot {--wa-numbers=} {--only=}';
    protected $description = 'Screenshot semua account dan kirim via WhatsApp';

    public function handle()
    {
        $query = Account::query();

        if ($only = $this->option('only')) {
            $ids = array_filter(explode(',', $only));
            $query->whereIn('id', $ids);
        }

        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            $this->error('Tidak ada account ditemukan.');
            return Command::FAILURE;
        }

        $phones = $this->resolvePhoneNumbers();

        if (empty($phones)) {
            $this->error('Nomor WA tujuan tidak ditemukan. Gunakan --wa-numbers=628xxx,628yyy atau set SCREENSHOT_WA_NUMBERS di .env');
            return Command::FAILURE;
        }

        $screenshots = [];

        foreach ($accounts as $account) {
            $this->info("Processing: {$account->email}...");

            Artisan::call('merchant:fetch-token', [
                '--email' => $account->email,
                '--pin' => $account->pin,
                '--screenshot' => true,
            ]);

            $ssPath = Cache::get('merchant_screenshot_'.$account->email);

            if ($ssPath && file_exists($ssPath)) {
                $screenshots[] = [
                    'email' => $account->email,
                    'path' => $ssPath,
                ];
                $this->info("Screenshot ready: {$ssPath}");
            } else {
                $this->warn("Screenshot tidak ditemukan untuk {$account->email}");
            }
        }

        if (empty($screenshots)) {
            $this->error('Tidak ada screenshot yang berhasil diambil.');
            return Command::FAILURE;
        }

        $this->info("Mengirim " . count($screenshots) . " screenshot ke " . count($phones) . " nomor...");

        foreach ($phones as $phone) {
            foreach ($screenshots as $ss) {
                try {
                    WhatsAppService::sendImage($phone, $ss['path']);
                    $this->info("Sent {$ss['email']} -> {$phone}");
                } catch (\Exception $e) {
                    $this->error("Gagal kirim {$ss['email']} -> {$phone}: {$e->getMessage()}");
                }
            }
        }

        $this->info('Selesai.');
        return Command::SUCCESS;
    }

    private function resolvePhoneNumbers(): array
    {
        if ($waNumbers = $this->option('wa-numbers')) {
            return array_filter(explode(',', $waNumbers));
        }

        return [
            '6285231731037', // Sultan
            '6285745219894', // Mama Luluk
            '6285704231663', // Istri
        ];
    }
}
