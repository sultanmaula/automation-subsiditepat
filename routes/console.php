<?php

use App\Models\Account;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    $accounts = Account::where('auto_retrieve', true)->get();

    Log::info('[Scheduler] account:auto-retrieve-all fired', ['total' => $accounts->count()]);

    foreach ($accounts as $account) {
        Artisan::call('account:auto-retrieve', ['account' => $account->id]);
        Log::info('[Scheduler] account:auto-retrieve done', ['account_id' => $account->id, 'email' => $account->email]);
    }
})->everyTenMinutes()->name('account:auto-retrieve-all')->withoutOverlapping();

// $allAccounts = Account::all();
// foreach ($allAccounts as $account) {
//     Schedule::command('account:auto-send-reminder ' . $account->id)
//         ->hourly();
// }

// Schedule::command('account:daily-recap')->hourly();
