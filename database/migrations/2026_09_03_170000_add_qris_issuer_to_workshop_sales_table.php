<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_sales', function (Blueprint $table) {
            $table->string('qris_issuer')->nullable()->after('qris_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('workshop_sales', function (Blueprint $table) {
            $table->dropColumn('qris_issuer');
        });
    }
};
