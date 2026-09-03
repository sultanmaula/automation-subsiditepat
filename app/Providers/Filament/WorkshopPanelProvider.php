<?php

namespace App\Providers\Filament;

use App\Http\Middleware\FilamentAuthenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use App\Filament\Workshop\Pages\ProductFinder;
use App\Filament\Workshop\Pages\WorkshopDashboard;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use App\Filament\Workshop\Widgets\LowStockWidget;
use App\Filament\Workshop\Widgets\RecentSalesWidget;
use App\Filament\Workshop\Widgets\WorkshopStatsWidget;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class WorkshopPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('workshop')
            ->path('/workshop')
            ->brandName('Jamus Motor')
            ->spa()
            ->sidebarCollapsibleOnDesktop()
            ->colors([
                'primary' => Color::Purple,
            ])
            ->discoverResources(in: app_path('Filament/Workshop/Resources'), for: 'App\\Filament\\Workshop\\Resources')
            ->discoverPages(in: app_path('Filament/Workshop/Pages'), for: 'App\\Filament\\Workshop\\Pages')
            ->pages([
                WorkshopDashboard::class,
                ProductFinder::class,
            ])
            // Urutan grup di sidebar ditentukan di sini, bukan oleh abjad.
            ->navigationGroups([
                'Kasir',
                'Inventori',
                'Laporan',
                'Pengaturan',
            ])
            ->navigationItems([
                NavigationItem::make('Layar Pembayaran')
                    ->url(fn (): string => route('workshop.display'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-device-phone-mobile')
                    ->group('Kasir')
                    ->sort(2)
                    ->visible(fn (): bool => auth()->user()?->hasPermission('display') ?? false),
            ])
            ->discoverWidgets(in: app_path('Filament/Workshop/Widgets'), for: 'App\\Filament\\Workshop\\Widgets')
            ->widgets([
                AccountWidget::class,
                WorkshopStatsWidget::class,
                RecentSalesWidget::class,
                LowStockWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                FilamentAuthenticate::class,
            ]);
    }
}
