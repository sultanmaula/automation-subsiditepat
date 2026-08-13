<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Ditandakan ketika API Pertamina membalas `DAILY_LIMIT_TRANSACTION` /
 * "Transaksi melebihi batas harian".
 *
 * Ini batas di level AKUN, bukan vonis atas NIK-nya. Karena itu seluruh chain
 * akun tersebut dihentikan untuk hari ini (seperti StockExhaustedException),
 * tapi NIK yang sedang diproses TIDAK dihukum — besok masih layak dicoba.
 */
class AccountDailyLimitException extends RuntimeException
{
}
