<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_products', function (Blueprint $table): void {
            $table->jsonb('compatible_models')->nullable()->default('[]')->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('workshop_products', function (Blueprint $table): void {
            $table->dropColumn('compatible_models');
        });
    }
};
