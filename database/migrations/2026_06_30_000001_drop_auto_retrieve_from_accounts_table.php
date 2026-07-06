<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Fitur Auto Retrieve (legacy) dihapus dan digantikan Auto Input NIK.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('auto_retrieve');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->boolean('auto_retrieve')->default(false)->index();
        });
    }
};
