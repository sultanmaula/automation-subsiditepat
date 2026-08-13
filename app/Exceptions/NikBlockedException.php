<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Ditandakan ketika API Pertamina (verify-nik) menandai NIK sebagai
 * `isBlocked` (mis. status MENINGGAL di data Dukcapil, atau alasan
 * permanen lain dari sisi Pertamina). NIK ini TIDAK akan pernah berhasil
 * diproses -> harus di-skip permanen (bukan dicoba ulang tiap 15 menit),
 * agar rotasi per-file (frontier) tidak macet menunggu NIK ini "sukses".
 */
class NikBlockedException extends RuntimeException
{
}
