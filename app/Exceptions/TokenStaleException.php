<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Ditandakan ketika token merchant yang masih dianggap valid secara lokal
 * (cache TTL 14 menit) ternyata ditolak Pertamina secara eksplisit (HTTP
 * 401/403). Beda dari error lain: NIK ini SEHARUSNYA bisa sukses, cuma
 * butuh token segar -> job harus di-retry cepat setelah token di-invalidate,
 * bukan di-skip permanen.
 */
class TokenStaleException extends RuntimeException
{
}
