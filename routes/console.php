<?php

use App\Models\Account;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// $accounts = Account::where('auto_retrieve', true)->get();
// foreach ($accounts as $account) {
//     Schedule::command('account:auto-retrieve ' . $account->id)
//         ->everyTenMinutes()
//         ->withoutOverlapping();
// }

// $allAccounts = Account::all();
// foreach ($allAccounts as $account) {
//     Schedule::command('account:auto-send-reminder ' . $account->id)
//         ->hourly();
// }

// Schedule::command('account:daily-recap')->hourly();