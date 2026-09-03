<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $roles = [
            'Pemilik' => array_keys(Role::WORKSHOP_PERMISSIONS),
            'Kasir'   => ['dashboard', 'sales', 'finder', 'display', 'products', 'stock'],
            'Pegawai' => ['finder', 'display'],
        ];

        foreach ($roles as $name => $permissions) {
            Role::updateOrCreate(
                ['panel' => 'workshop', 'name' => $name],
                ['permissions' => $permissions],
            );
        }

        // Pindahkan pengguna bengkel yang sudah ada ke role yang setara, supaya
        // tidak ada yang kehilangan atau mendapat akses saat migrasi jalan.
        $pemilik = Role::forPanel('workshop')->where('name', 'Pemilik')->first();
        $pegawai = Role::forPanel('workshop')->where('name', 'Pegawai')->first();

        User::where('role', 'workshop')->whereNull('role_id')->get()
            ->each(function (User $user) use ($pemilik, $pegawai): void {
                // permissions kosong selama ini berarti akses penuh.
                $user->update([
                    'role_id' => empty($user->permissions) ? $pemilik->id : $pegawai->id,
                    // Dikosongkan supaya tidak ada dua sumber kebenaran yang
                    // bisa berbeda diam-diam; role_id yang berlaku sekarang.
                    'permissions' => [],
                ]);
            });
    }

    public function down(): void
    {
        User::whereNotNull('role_id')->update(['role_id' => null]);
        Role::forPanel('workshop')->whereIn('name', ['Pemilik', 'Kasir', 'Pegawai'])->delete();
    }
};
