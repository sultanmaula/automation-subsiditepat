<?php

use App\Models\Account;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:check-accounts-stock')
    ->everyThirtyMinutes()
    ->name('app:check-accounts-stock')
    ->withoutOverlapping();

Schedule::call(function () {
    $accounts = Account::where('auto_input_nik', true)->get();

    Log::info('[Scheduler] nik:auto-input-all fired', ['total' => $accounts->count()]);

    foreach ($accounts as $account) {
        Artisan::call('nik:auto-input', ['account_id' => $account->id]);
        Log::info('[Scheduler] nik:auto-input done', ['account_id' => $account->id, 'email' => $account->email]);
    }
})->everyMinute()->name('nik:auto-input-all')->withoutOverlapping();

// $allAccounts = Account::all();
// foreach ($allAccounts as $account) {
//     Schedule::command('account:auto-send-reminder ' . $account->id)
//         ->hourly();
// }

Schedule::command('account:weekly-recap')
    ->sundays()
    ->at('07:00')
    ->timezone('Asia/Jakarta')
    ->name('account:weekly-recap')
    ->withoutOverlapping();

Schedule::command('account:weekly-recap')
    ->lastDayOfMonth('19:00')
    ->timezone('Asia/Jakarta')
    ->name('account:weekly-recap-akhir-bulan')
    ->withoutOverlapping();

Schedule::command('db:backup')
    ->daily()
    ->at('02:00')
    ->timezone('Asia/Jakarta')
    ->name('db:backup')
    ->withoutOverlapping();
