<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Ditandakan ketika API Pertamina membalas `NOT_FOUND` / "Data pelanggan tidak
 * ditemukan" — NIK tidak terdaftar sebagai penerima subsidi.
 *
 * Berbeda dengan NikQuotaExhaustedException yang hanya berlaku sebulan, ini
 * permanen: NIK yang tidak terdaftar hari ini tidak akan tiba-tiba terdaftar
 * bulan depan. Ditandai `is_failed` seperti NikBlockedException supaya tidak
 * pernah masuk rotasi lagi.
 */
class NikNotRegisteredException extends RuntimeException
{
}
