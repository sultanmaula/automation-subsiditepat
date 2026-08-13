<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use App\Models\Account;
use Illuminate\Support\Str;

class FetchMerchantToken extends Command
{
    protected $signature = 'merchant:fetch-token {--email=} {--pin=} {--screenshot}';
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
            'SCREENSHOT' => $this->option('screenshot') ? '1' : '0',
            'SCREENSHOT_DIR' => storage_path('app/screenshots'),
        ];

        if ($execPath = env('PUPPETEER_EXECUTABLE_PATH')) {
            $env['PUPPETEER_EXECUTABLE_PATH'] = $execPath;
        }

        $process = new Process([$node, $script], base_path('scripts'), $env);
        $process->setTimeout(120);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            Log::error('[merchant:fetch-token] proses Puppeteer timeout', [
                'email' => $email,
            ]);
            $this->error('Failed: process timed out');
            return Command::FAILURE;
        }

        $out = $process->getOutput() ?: $process->getErrorOutput();
        $data = json_decode(trim($out), true);

        if (!empty($data['token'])) {
            Cache::put('merchant_api_token_'.$email, $data['token'], now()->addMinutes(14));
            Account::where('email', $email)->update(['last_update_api' => now()]);

            if ($this->option('screenshot') && !empty($data['screenshot'])) {
                Cache::put('merchant_screenshot_'.$email, $data['screenshot'], now()->addMinutes(30));
                $this->info("Screenshot saved: {$data['screenshot']}");
            }

            $this->info('Token saved to cache.');
            return Command::SUCCESS;
        }

        $reason = $data['error'] ?? $out ?? 'no output';

        Log::error('[merchant:fetch-token] gagal ambil token', [
            'email'     => $email,
            'exit_code' => $process->getExitCode(),
            'reason'    => Str::limit((string) $reason, 500),
        ]);

        $this->error('Failed: ' . $reason);
        return Command::FAILURE;
    }
}
