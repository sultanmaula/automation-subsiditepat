<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Jadikan unique (account_id, order) DEFERRABLE INITIALLY DEFERRED agar
     * drag & drop reorder Filament (satu UPDATE ... CASE dalam transaksi) tidak
     * gagal duplicate key saat menukar nilai order; constraint dicek saat commit.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE account_document_orders DROP CONSTRAINT account_document_orders_account_id_order_unique');
        DB::statement('ALTER TABLE account_document_orders ADD CONSTRAINT account_document_orders_account_id_order_unique UNIQUE (account_id, "order") DEFERRABLE INITIALLY DEFERRED');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE account_document_orders DROP CONSTRAINT account_document_orders_account_id_order_unique');
        DB::statement('ALTER TABLE account_document_orders ADD CONSTRAINT account_document_orders_account_id_order_unique UNIQUE (account_id, "order")');
    }
};
