<?php

namespace App\Filament\Workshop\Pages;

use Filament\Pages\Dashboard;

class WorkshopDashboard extends Dashboard
{
    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = -2;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasPermission('dashboard') ?? false;
    }

    /**
     * Dashboard adalah halaman root panel, jadi pengguna tanpa izin akan
     * mendarat di sini setelah login. Dialihkan, bukan dibalas 403, supaya
     * pegawai langsung sampai di halaman yang memang boleh mereka buka.
     */
    public function mountCanAuthorizeAccess(): void
    {
        $user = auth()->user();

        if ($user?->hasPermission('dashboard')) {
            return;
        }

        if (ProductFinder::canAccess()) {
            $this->redirect(ProductFinder::getUrl());

            return;
        }

        abort_unless($user?->hasPermission('display'), 403);

        $this->redirect(route('workshop.display'));
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
