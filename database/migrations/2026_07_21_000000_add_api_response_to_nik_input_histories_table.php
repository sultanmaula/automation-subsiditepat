<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menyimpan response mentah dari API Pertamina saat NIK diproses (body sukses
 * submit-transaction, ATAU body penolakan untuk kuota habis / NIK tak terdaftar
 * / diblokir). Sebelumnya response ini hanya masuk log lalu dibuang.
 *
 * Diisi ke DEPAN oleh ProcessNikJob::saveHistory(); baris lama yang sudah ada
 * akan bernilai null (tidak ada response tersimpan saat itu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nik_input_histories', function (Blueprint $table) {
            $table->text('api_response')->nullable()->after('rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('nik_input_histories', function (Blueprint $table) {
            $table->dropColumn('api_response');
        });
    }
};
