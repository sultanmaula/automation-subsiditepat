<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('nik_input_histories', function (Blueprint $table) {
            // DROP constraint lama
            $table->dropUnique('nik_input_histories_account_id_input_date_unique');

            // ADD constraint baru
            $table->unique(
                ['account_id', 'nik', 'input_date'],
                'nik_input_histories_account_nik_date_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('nik_input_histories', function (Blueprint $table) {
            $table->dropUnique('nik_input_histories_account_nik_date_unique');

            $table->unique(
                ['account_id', 'input_date'],
                'nik_input_histories_account_id_input_date_unique'
            );
        });
    }
};
