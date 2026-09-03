<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'role_id',
        'permissions',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
        ];
    }

    /**
     * Role izin di dalam panel. Sengaja TIDAK dinamai role() karena kolom
     * users.role sudah dipakai sebagai penentu panel (workshop / lpg).
     */
    public function accessRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->role === 'admin') {
            return true;
        }

        // Role adalah sumber kebenaran begitu dipasang.
        if ($this->role_id) {
            return $this->accessRole?->hasPermission($permission) ?? false;
        }

        // Belum punya role: kolom permissions lama tetap dihormati, dan daftar
        // kosong berarti pemilik dengan akses penuh (perilaku sejak awal).
        if (empty($this->permissions)) {
            return true;
        }

        return in_array($permission, $this->permissions, true);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'lpg' => $this->role === 'lpg',
            'workshop' => $this->role === 'workshop',
            default => false,
        };
    }
}
