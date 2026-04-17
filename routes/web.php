<?php

use App\Filament\Auth\Pages\Login;
use App\Http\Controllers\NikInputController;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->user()) {
        if (auth()->user()->role == 'lpg') {
            return redirect('/lpg');
        } else if (auth()->user()->role == 'workshop') {
            return redirect('/workshop');
        }
    } else {
        return redirect('/login');
    }
});

Route::get('/login', Login::class)
    ->middleware(Filament::getPanel('lpg')->getMiddleware())
    ->name('login');

Route::get('/nik-input', [NikInputController::class, 'deleteLastMonthApi']);

Route::get('/check-db', function () {
    return \Illuminate\Support\Facades\DB::select('SELECT name FROM sqlite_master WHERE type="table"');
});