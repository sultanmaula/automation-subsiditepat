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
        Schema::table('workshop_sales', function (Blueprint $table) {
            $table->timestamp('qris_expires_at')->nullable()->after('qris_checkout_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workshop_sales', function (Blueprint $table) {
            $table->dropColumn('qris_expires_at');
        });
    }
};
