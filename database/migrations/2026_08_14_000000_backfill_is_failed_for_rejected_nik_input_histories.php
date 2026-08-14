<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Baris ber-`rejected_status` adalah penolakan Pertamina, bukan pembelian.
 * Sebelum ini flag `is_failed`-nya dibiarkan false, sehingga penghitung yang
 * hanya memfilter `is_failed = false` (rekap harian VerifyNikTransactionCommand,
 * chart per akun, total input di daftar akun) menghitungnya sebagai sukses.
 *
 * Invarian sekarang dijaga di model NikInputHistory; migrasi ini merapikan
 * baris lama. Tidak reversible: false yang lama tidak bisa dibedakan lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('nik_input_histories')
            ->whereNotNull('rejected_status')
            ->where('is_failed', false)
            ->update(['is_failed' => true]);
    }

    public function down(): void
    {
        // sengaja no-op
    }
};
