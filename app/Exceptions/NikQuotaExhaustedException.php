<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Ditandakan ketika API Pertamina menolak sebuah NIK karena kuotanya sudah
 * habis bulan ini:
 *
 *  - `TRANSACTION_INVALID`  -> NIK sudah mencapai batas pembelian bulan ini.
 *  - `TRANSACTION_ANOMALY` / `NOT_ALLOWED_TRANSACTION` -> NIK sudah bertransaksi
 *    di tempat lain (respons menyertakan provinceName/cityName/transactionDate).
 *
 * PENTING: NIK dalam dokumen kita juga bisa dibeli lewat pangkalan lain di luar
 * sistem ini, jadi hitungan lokal di `nik_input_histories` TIDAK PERNAH bisa
 * jadi sumber kebenaran — hanya jawaban Pertamina yang otoritatif.
 *
 * Sebelumnya penolakan ini jatuh ke RuntimeException generic dan diperlakukan
 * sebagai error sementara, sehingga NIK yang sudah pasti ditolak digedor ulang
 * setiap hari sepanjang bulan (2624 kejadian di log Juli 2026). Sekarang
 * dicatat sebagai `rejected_status` dan diistirahatkan sampai kuota reset
 * bulan depan.
 */
class NikQuotaExhaustedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $status = 'TRANSACTION_INVALID',
    ) {
        parent::__construct($message);
    }
}
