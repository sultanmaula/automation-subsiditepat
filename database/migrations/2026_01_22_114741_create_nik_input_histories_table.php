<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('nik_input_histories', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('data_master_document_id');
            $table->unsignedBigInteger('data_nik_input_id');
            $table->string('nik', 20);
            $table->date('input_date');
            $table->char('input_month', 7); // YYYY-MM
            $table->timestamps();
            $table->foreign('data_master_document_id')
                  ->references('id')
                  ->on('data_master_documents')
                  ->cascadeOnDelete();

            $table->foreign('data_nik_input_id')
                  ->references('id')
                  ->on('data_nik_inputs')
                  ->cascadeOnDelete();

            /**
             * HARD RULE PROTECTION
             */

            // 1 hari = 1 NIK (per akun)
            $table->unique(['account_id', 'input_date']);

            // NIK lookup cepat
            $table->index(['account_id', 'nik', 'input_month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nik_input_histories');
    }
};
