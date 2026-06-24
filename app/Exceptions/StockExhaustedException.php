<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Ditandakan ketika API Pertamina menolak transaksi karena stok harian
 * sudah habis ("Transaksi melebihi stok yang dapat dijual").
 *
 * Dipakai untuk MENGHENTIKAN seluruh rangkaian (chain) input NIK — tidak ada
 * gunanya melanjutkan NIK berikutnya bila stok sudah nol.
 */
class StockExhaustedException extends RuntimeException
{
}
