<?php

namespace App\Filament\Widgets;

use App\Models\Account;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class StockPerAccountWidget extends Widget
{
    protected string $view = 'filament.widgets.stock-per-account';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '60s';

    public function refreshStock(): void
    {
        Account::all()->each(function (Account $account): void {
            Cache::forget("stock_data_{$account->email}");
        });
    }

    protected function getViewData(): array
    {
        $accounts = Account::orderBy('id')->get();
        $stockData = [];

        foreach ($accounts as $account) {
            $cacheKey = "stock_data_{$account->email}";
            $cached   = Cache::get($cacheKey);

            if ($cached !== null) {
                $stockData[] = $cached;
                continue;
            }

            $token = Cache::get("merchant_api_token_{$account->email}");

            if (!$token) {
                $stockData[] = [
                    'email'          => $account->email,
                    'storeName'      => null,
                    'stockAvailable' => null,
                    'stockDate'      => null,
                    'lastSyncAt'     => null,
                    'fetched_at'     => null,
                    'error'          => 'Token tidak tersedia',
                ];
                continue;
            }

            $res = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
            ])->get('https://api-map.my-pertamina.id/general/products/v1/products/user');

            if ($res->successful() && ($res->json('code') === 200 || $res->json('code') === '200') && $res->json('status') === 'OK') {
                $data  = $res->json('data') ?? [];
                $entry = [
                    'email'          => $account->email,
                    'storeName'      => $data['storeName']      ?? null,
                    'stockAvailable' => $data['stockAvailable'] ?? null,
                    'stockDate'      => $data['stockDate']      ?? null,
                    'lastSyncAt'     => $data['lastSyncAt']     ?? null,
                    'fetched_at'     => now()->format('H:i:s'),
                    'error'          => null,
                ];

                Cache::put($cacheKey, $entry, now()->addMinutes(5));
                $stockData[] = $entry;
            } else {
                if ($res->status() === 401) {
                    Cache::forget("merchant_api_token_{$account->email}");
                }

                $stockData[] = [
                    'email'          => $account->email,
                    'storeName'      => null,
                    'stockAvailable' => null,
                    'stockDate'      => null,
                    'lastSyncAt'     => null,
                    'fetched_at'     => null,
                    'error'          => $res->status() === 401 ? 'Token kedaluwarsa' : 'Gagal mengambil data stok',
                ];
            }
        }

        return ['stockData' => $stockData];
    }
}
