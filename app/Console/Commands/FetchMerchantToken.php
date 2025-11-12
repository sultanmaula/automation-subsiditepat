<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use App\Models\Account;

class FetchMerchantToken extends Command
{
    protected $signature = 'merchant:fetch-token {--email=} {--pin=}';
    protected $description = 'Fetch bearer token via Puppeteer';

    public function handle()
    {
        $email = $this->option('email');
        $pin = $this->option('pin');

        if (!$email || !$pin) {
            $this->error('Please fill Email and Pin!');
            return Command::FAILURE;
        }
        
        $node = trim(shell_exec('which node') ?: '/usr/bin/node');
        $script = base_path('scripts/get-token.cjs');

        $env = [
            'AUTH_EMAIL' => $email,
            'AUTH_PIN'   => $pin,
            'LOGIN_URL'  => env('MERCHANT_LOGIN_URL', 'https://subsiditepatlpg.mypertamina.id/merchant-login'),
            'SEL_EMAIL'  => env('MERCHANT_SEL_EMAIL', 'input[placeholder="Masukkan Nomor Ponsel atau Email"]'),
            'SEL_PIN'    => env('MERCHANT_SEL_PIN', 'input[placeholder="Masukkan nomor PIN Anda"]'),
            'SEL_SUBMIT' => env('MERCHANT_SEL_SUBMIT', 'button[type="submit"]:has-text("MASUK")'),
            'TOKEN_URL_HINT' => env('MERCHANT_TOKEN_URL_HINT', 'https://api-map.my-pertamina.id/general/products/v1/products/user'),
            'NODE_ENV'   => 'production',
            'HOME' => env('PUPPETEER_HOME', '/var/www'),
            'PUPPETEER_CACHE_DIR' => env('PUPPETEER_CACHE_DIR', base_path('.cache/puppeteer')),
            'PUPPETEER_EXECUTABLE_PATH' => env('PUPPETEER_EXECUTABLE_PATH', '/usr/bin/chromium'),
        ];

        $process = new Process([$node, $script], base_path('scripts'), $env);
        $process->setTimeout(120);
        $process->run();

        $out = $process->getOutput() ?? $process->getErrorOutput();
        $data = json_decode(trim($out), true);

        if (!empty($data['token'])) {
            Cache::put('merchant_api_token_'.$email, $data['token'], now()->addMinutes(10));
            Account::where('email', $email)->update(['last_update_api' => now()]);

            $this->info('Token saved to cache.');
            return Command::SUCCESS;
        }

        $this->error('Failed: ' . ($out ?? 'no output'));
        return Command::FAILURE;
    }
}
