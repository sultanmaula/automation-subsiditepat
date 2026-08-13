<?php

namespace App\Jobs;

use App\Models\Account;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class FetchMerchantTokenJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 150;
    public int $tries = 1;

    public function __construct(public int $accountId) {}

    public function uniqueId(): string
    {
        return (string) $this->accountId;
    }

    public function handle(): void
    {
        $account = Account::find($this->accountId);

        if (! $account) {
            return;
        }

        Artisan::call('merchant:fetch-token', [
            '--email' => $account->email,
            '--pin'   => $account->pin,
        ]);
    }
}
