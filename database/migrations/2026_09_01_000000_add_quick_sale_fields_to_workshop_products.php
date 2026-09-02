<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_products', function (Blueprint $table): void {
            $table->boolean('is_quick_sale')->default(false)->after('is_active');
            $table->unsignedSmallInteger('quick_sort')->default(0)->after('is_quick_sale');
        });
    }

    public function down(): void
    {
        Schema::table('workshop_products', function (Blueprint $table): void {
            $table->dropColumn(['is_quick_sale', 'quick_sort']);
        });
    }
};
