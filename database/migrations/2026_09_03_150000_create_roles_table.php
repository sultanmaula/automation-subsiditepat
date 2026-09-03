<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // Panel pemilik role. Dipisah supaya role bengkel tidak pernah
            // muncul atau bisa dipasang di Panel LPG, dan sebaliknya.
            $table->string('panel')->default('workshop');
            $table->jsonb('permissions')->default('[]');
            $table->timestamps();

            $table->unique(['panel', 'name']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('role_id')->nullable()->after('role')
                ->constrained('roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('role_id');
        });

        Schema::dropIfExists('roles');
    }
};
