<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_sales', function (Blueprint $table): void {
            // Jejak pembatalan, supaya "kenapa transaksi ini hilang dari omzet"
            // masih bisa dijawab berbulan-bulan kemudian.
            $table->timestamp('cancelled_at')->nullable()->after('qris_expires_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                ->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable()->after('cancelled_by');

            // Penanda bahwa stok yang ditahan transaksi ini sudah dikembalikan.
            // Dipakai sebagai kunci idempotensi: pengembalian dan penarikan
            // ulang stok boleh dipanggil berkali-kali tanpa menggandakan mutasi.
            $table->timestamp('stock_released_at')->nullable()->after('cancel_reason');
        });

        // Webhook dan rekonsiliasi sama-sama mencari baris lewat kolom ini.
        Schema::table('workshop_sales', function (Blueprint $table): void {
            $table->index('qris_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('workshop_sales', function (Blueprint $table): void {
            $table->dropIndex(['qris_transaction_id']);
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason', 'stock_released_at']);
        });
    }
};
