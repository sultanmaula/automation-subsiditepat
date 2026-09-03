<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_sales', function (Blueprint $table) {
            $table->string('payment_status')->default('paid')->after('status');
            $table->string('qris_transaction_id')->nullable()->after('payment_status');
            $table->string('qris_qr_url')->nullable()->after('qris_transaction_id');
            $table->string('qris_checkout_url')->nullable()->after('qris_qr_url');
        });
    }

    public function down(): void
    {
        Schema::table('workshop_sales', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'qris_transaction_id', 'qris_qr_url', 'qris_checkout_url']);
        });
    }
};
