<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Ditandakan ketika Pertamina sendiri membalas rate-limit (code 429 /
 * "TOO_MANY_REQUEST"), DI LUAR RateLimiter internal kita (10 hit/60 detik/akun).
 * Beda dari TokenStaleException: ini bukan soal token, jadi JANGAN invalidate
 * token -> cukup tunggu sejenak lalu retry NIK yang sama dengan token yang sama.
 */
class PertaminaRateLimitedException extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds = 10)
    {
        parent::__construct($message);
    }
}
