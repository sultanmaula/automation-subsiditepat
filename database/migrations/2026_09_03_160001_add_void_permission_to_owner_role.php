<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Izin 'void' baru, jadi role yang sudah ada tidak otomatis memilikinya.
     * Hanya Pemilik yang boleh membatalkan transaksi; kasir tidak, karena
     * pembatalan menghapus omzet sekaligus mengembalikan stok.
     */
    public function up(): void
    {
        $owner = Role::forPanel('workshop')->where('name', 'Pemilik')->first();

        if (! $owner) {
            return;
        }

        $owner->update([
            'permissions' => array_values(array_unique([...$owner->permissions ?? [], 'void'])),
        ]);
    }

    public function down(): void
    {
        $owner = Role::forPanel('workshop')->where('name', 'Pemilik')->first();

        if (! $owner) {
            return;
        }

        $owner->update([
            'permissions' => array_values(array_diff($owner->permissions ?? [], ['void'])),
        ]);
    }
};
