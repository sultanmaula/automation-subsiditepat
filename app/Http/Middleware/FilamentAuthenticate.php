<?php

namespace App\Http\Middleware;

use Filament\Http\Middleware\Authenticate as BaseFilamentAuthenticate;

class FilamentAuthenticate extends BaseFilamentAuthenticate
{
    protected function redirectTo($request): ?string
    {
        return '/login';
    }
}
