<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Satu pintu untuk ambil token merchant dari cache, dengan lock per-akun agar
 * dua proses (nik:auto-input & app:check-accounts-stock) tidak sama-sama buka
 * sesi Puppeteer untuk akun yang sama secara bersamaan (saling menendang login).
 */
class MerchantTokenService
{
    public static function getOrFetch(Account $account): ?string
    {
        $cacheKey = "merchant_api_token_{$account->email}";

        $token = Cache::get($cacheKey);
        if ($token) {
            return $token;
        }

        $lock = Cache::lock("merchant_token_fetch_lock:{$account->email}", 130);

        try {
            $lock->block(130, function () use ($account, $cacheKey) {
                // Double-check: proses lain mungkin baru saja selesai fetch saat kita menunggu lock.
                if (! Cache::get($cacheKey)) {
                    Artisan::call('merchant:fetch-token', [
                        '--email' => $account->email,
                        '--pin'   => $account->pin,
                    ]);
                }
            });
        } catch (LockTimeoutException) {
            // Proses lain masih memegang lock lebih dari 130s; lanjut tanpa token (ditangani caller).
        }

        return Cache::get($cacheKey);
    }

    /**
     * Buang token dari cache (mis. dipanggil setelah API balas 401) supaya
     * pemanggilan berikutnya fetch token baru, bukan mengulang token basi
     * sampai TTL cache (14 menit) habis sendiri.
     */
    public static function invalidate(Account $account): void
    {
        Cache::forget("merchant_api_token_{$account->email}");
    }
}
