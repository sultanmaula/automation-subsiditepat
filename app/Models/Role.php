<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'panel',
        'permissions',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }

    /**
     * Daftar izin yang tersedia di Panel Bengkel.
     * Kunci dipakai di canAccess()/shouldRegisterNavigation(), nilainya label form.
     */
    public const WORKSHOP_PERMISSIONS = [
        'dashboard'  => 'Dashboard',
        'sales'      => 'POS / Transaksi',
        'finder'     => 'Cari Barang',
        'display'    => 'Layar Pembayaran QRIS',
        'products'   => 'Produk',
        'categories' => 'Kategori',
        'stock'      => 'Stock Opname & Riwayat Stok',
        'reports'    => 'Laporan Penjualan',
        'users'      => 'Manajemen Pengguna',
        'roles'      => 'Manajemen Role',
        'void'       => 'Batalkan Transaksi',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? [], true);
    }

    public function scopeForPanel($query, string $panel)
    {
        return $query->where('panel', $panel);
    }
}
