<?php

namespace App\Filament\Workshop\Pages;

use Filament\Pages\Dashboard;

class WorkshopDashboard extends Dashboard
{
    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = -2;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
