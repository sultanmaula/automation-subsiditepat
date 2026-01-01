<?php

namespace App\Filament\Auth\Responses;

use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportRedirects\Redirector;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse | Redirector
    {
        $user = Auth::user();

        if ($user?->role === 'workshop') {
            return redirect()->to($this->intendedOrDefault('/workshop'));
        }

        if (in_array($user?->role, ['lpg', 'admin'], true)) {
            return redirect()->to($this->intendedOrDefault('/lpg'));
        }

        return redirect()->to('/lpg');
    }

    private function intendedOrDefault(string $defaultPath): string
    {
        $intended = session()->get('url.intended');

        if (!is_string($intended)) {
            return $defaultPath;
        }

        $path = parse_url($intended, PHP_URL_PATH);

        if (!is_string($path) || !str_starts_with($path, $defaultPath)) {
            return $defaultPath;
        }

        return $intended;
    }
}
