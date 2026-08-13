<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Ditandakan saat Pertamina membalas `NOT_ACCEPTABLE` / code 500
 * ("Terjadi kesalahan pada sistem kami").
 *
 * Ini TETAP transient — NIK tidak dihanguskan dan tidak dicatat ke history,
 * persis seperti sebelumnya. Bedanya sekarang dihitung: kalau beberapa NIK
 * berturut-turut dibalas begini, chain dihentikan dan akun didiamkan sebentar
 * alih-alih terus menembak.
 *
 * Penyebabnya bisa DUA hal dan sengaja tidak dibeda-bedakan:
 *  - dokumen habis (lihat memori doc-exhaustion-500-fix: akun 3 #17 -> #18), atau
 *  - gelombang gangguan sisi Pertamina per-merchant (17 Jul 2026: NIK dari
 *    dokumen sehat pun ikut kena, pulih sendiri 2-4 jam).
 *
 * Karena teori "dokumen tertentu yang rusak" pernah GUGUR saat ditelusuri,
 * exception ini SENGAJA tidak dipakai untuk mencoret dokumen dari rotasi —
 * kalau dipakai begitu, satu gelombang outage akan menghanguskan seluruh
 * antrean dokumen padahal tidak ada yang salah. Yang dilakukan hanya berhenti
 * menggedor, yang benar di kedua kemungkinan.
 */
class PertaminaSystemErrorException extends RuntimeException
{
}
