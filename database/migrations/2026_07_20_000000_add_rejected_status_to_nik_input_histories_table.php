<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mencatat penolakan tegas dari Pertamina per NIK (TRANSACTION_INVALID,
 * TRANSACTION_ANOMALY) supaya NIK yang kuotanya sudah habis tidak dicoba ulang
 * setiap hari sepanjang bulan.
 *
 * Baris dengan `rejected_status` terisi BUKAN input sukses: setiap hitungan
 * putaran bulanan wajib memfilter `whereNull('rejected_status')`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nik_input_histories', function (Blueprint $table) {
            $table->string('rejected_status', 40)->nullable()->index()->after('is_failed');
            $table->timestamp('rejected_at')->nullable()->after('rejected_status');
        });
    }

    public function down(): void
    {
        Schema::table('nik_input_histories', function (Blueprint $table) {
            $table->dropIndex(['rejected_status']);
            $table->dropColumn(['rejected_status', 'rejected_at']);
        });
    }
};
