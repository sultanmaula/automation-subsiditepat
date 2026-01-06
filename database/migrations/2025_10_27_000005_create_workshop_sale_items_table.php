<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_sale_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id')->constrained('workshop_sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('workshop_products')->restrictOnDelete();
            $table->integer('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_sale_items');
    }
};
