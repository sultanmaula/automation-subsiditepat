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

Route::middleware('auth')->get('/workshop/nota/{id}', function ($id) {
    $sale = \App\Models\Workshop\Sale::with(['items.product', 'cashier'])->findOrFail($id);
    return view('workshop.nota', compact('sale'));
})->name('workshop.nota');

Route::middleware('auth')->get('/workshop/sale/{id}/payment-status', function ($id) {
    $sale = \App\Models\Workshop\Sale::findOrFail($id);

    // Check TTL dulu untuk auto-expire jika sudah lewat waktu
    $sale->checkAndUpdateQrisExpired();
    $sale->refresh();

    return response()->json(['payment_status' => $sale->payment_status]);
})->name('workshop.sale.payment-status');

// Layar pembayaran untuk HP pegawai yang mengantar QR ke customer.
Route::middleware('auth')->group(function () {
    Route::get('/workshop/display', function () {
        abort_unless(auth()->user()?->hasPermission('display'), 403);

        return view('workshop.display');
    })->name('workshop.display');

    Route::get('/workshop/display/data', function () {
        abort_unless(auth()->user()?->hasPermission('display'), 403);

        $sales = \App\Models\Workshop\Sale::query()
            ->where('payment_method', 'qris')
            ->whereIn('payment_status', ['pending', 'settlement'])
            ->whereNotNull('qris_qr_url')
            ->where('created_at', '>=', now()->subHours(2))
            ->orderByDesc('created_at')
            ->get();

        // Tandai yang sudah lewat TTL supaya tidak terus tampil menunggu.
        $sales->each->checkAndUpdateQrisExpired();

        return response()->json(
            $sales->fresh()
                ->where('payment_status', '!=', 'expired')
                ->map(fn ($sale) => [
                    'id'             => $sale->id,
                    'sale_number'    => $sale->sale_number,
                    'customer_name'  => $sale->customer_name,
                    'total'          => (float) $sale->total,
                    'payment_status' => $sale->payment_status,
                    'qr_url'         => $sale->qris_qr_url,
                    'expires_at'     => optional($sale->qris_expires_at)->timestamp,
                ])
                ->values()
        );
    })->name('workshop.display.data');
});

Route::match(['get', 'post'], '/webhook/autogopay', \App\Http\Controllers\AutoGoPayWebhookController::class)
    ->name('webhook.autogopay')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);