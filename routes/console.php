<?php

use App\Models\Account;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$accounts = Account::where('auto_retrieve', true)->get();
foreach ($accounts as $account) {
    Schedule::command('account:auto-retrieve ' . $account->id)
        ->everyMinute()
        ->withoutOverlapping();
}