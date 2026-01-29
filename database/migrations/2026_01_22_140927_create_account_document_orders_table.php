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
        Schema::create('account_document_orders', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('data_master_document_id');

            // Urutan dokumen khusus per akun
            $table->unsignedInteger('order');

            $table->timestamps();

            /**
             * CONSTRAINT & INDEX
             */

            // 1 akun tidak boleh punya dokumen yang sama dua kali
            $table->unique(['account_id', 'data_master_document_id']);

            // 1 akun tidak boleh punya urutan dobel
            $table->unique(['account_id', 'order']);

            $table->index(['account_id', 'order']);

            /**
             * FOREIGN KEY
             */
            $table->foreign('account_id')
                ->references('id')
                ->on('accounts')
                ->cascadeOnDelete();

            $table->foreign('data_master_document_id')
                ->references('id')
                ->on('data_master_documents')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_document_orders');
    }
};
