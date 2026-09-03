<?php

namespace App\Filament\Workshop\Resources\Roles\Pages;

use App\Filament\Workshop\Resources\Roles\RoleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->disabled(fn (): bool => $this->getRecord()->users()->exists()),
        ];
    }
}
