<?php

namespace Tests\Feature;

use App\Exceptions\AccountDailyLimitException;
use App\Exceptions\NikNotRegisteredException;
use App\Exceptions\NikQuotaExhaustedException;
use App\Jobs\ProcessNikJob;
use App\Models\Account;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Penolakan tegas dari Pertamina datang sebagai HTTP 400, jadi kalau
 * `$res->failed()` diperiksa lebih dulu semuanya tertelan menjadi
 * RuntimeException generic dan diperlakukan sebagai error sementara —
 * itulah bug yang membuat NIK habis kuota digedor ulang tiap hari
 * (2624 TRANSACTION_INVALID di log Juli 2026).
 *
 * Body JSON di bawah disalin apa adanya dari storage/logs/laravel.log.
 */
class NikRejectionClassificationTest extends TestCase
{
    private function verifyNikWithResponse(array $body, int $status = 400): void
    {
        Http::fake([
            '*verify-nik*' => Http::response($body, $status),
        ]);

        $job = new ProcessNikJob(
            account_id: 1,
            data_master_document_id: 1,
            data_nik_input_id: 1,
        );

        $method = new ReflectionMethod($job, 'verifyNik');
        $method->setAccessible(true);

        $method->invoke($job, 'dummy-token', '1234567890123456', new Account(['email' => 'a@b.c']));
    }

    public function test_transaction_invalid_dianggap_kuota_habis(): void
    {
        $this->expectException(NikQuotaExhaustedException::class);

        $this->verifyNikWithResponse([
            'success' => false,
            'data'    => '',
            'message' => 'TRANSACTION_INVALID',
            'code'    => 400,
            'status'  => 'TRANSACTION_INVALID',
        ]);
    }

    public function test_transaction_anomaly_dianggap_kuota_habis(): void
    {
        try {
            $this->verifyNikWithResponse([
                'success' => false,
                'code'    => 400,
                'message' => 'NOT_ALLOWED_TRANSACTION',
                'status'  => 'TRANSACTION_ANOMALY',
                'data'    => [
                    'provinceName'    => 'KEPULAUAN RIAU',
                    'cityName'        => 'KOTA BATAM',
                    'transactionDate' => '18 Juli 2026',
                ],
            ]);

            $this->fail('NikQuotaExhaustedException tidak dilempar');
        } catch (NikQuotaExhaustedException $e) {
            // Status mentah ikut tersimpan supaya bisa dibedakan saat audit.
            $this->assertSame('TRANSACTION_ANOMALY', $e->status);
        }
    }

    public function test_not_found_dianggap_nik_tidak_terdaftar(): void
    {
        $this->expectException(NikNotRegisteredException::class);

        $this->verifyNikWithResponse([
            'success' => false,
            'code'    => 404,
            'message' => 'Data pelanggan tidak ditemukan',
            'data'    => '',
            'status'  => 'NOT_FOUND',
        ], 404);
    }

    public function test_daily_limit_dianggap_batas_akun(): void
    {
        $this->expectException(AccountDailyLimitException::class);

        $this->verifyNikWithResponse([
            'success' => false,
            'code'    => 400,
            'message' => 'Transaksi melebihi batas harian',
            'data'    => '',
            'status'  => 'DAILY_LIMIT_TRANSACTION',
        ]);
    }

    /**
     * Gelombang 500 dari sisi Pertamina BUKAN vonis atas NIK-nya — harus tetap
     * transient supaya NIK dicoba lagi, bukan dihanguskan sebulan.
     */
    public function test_not_acceptable_tetap_transient(): void
    {
        $this->expectException(RuntimeException::class);

        try {
            $this->verifyNikWithResponse([
                'success' => false,
                'code'    => 406,
                'message' => 'Terjadi kesalahan pada sistem kami',
                'data'    => '',
                'status'  => 'NOT_ACCEPTABLE',
            ], 406);
        } catch (NikQuotaExhaustedException|NikNotRegisteredException|AccountDailyLimitException $e) {
            $this->fail('NOT_ACCEPTABLE tidak boleh dianggap penolakan permanen: ' . $e::class);
        }
    }
}
