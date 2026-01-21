<?php

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class AutoRetrieveDataAccounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'account:auto-retrieve';

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
        $accounts = Account::where('auto_retrieve', true)->get();
        
        foreach ($accounts ?? null as $account) {
            Artisan::call('merchant:fetch-token', [
                '--email' => $account->email,
                '--pin'   => $account->pin,
            ]);
        }
    }
}
