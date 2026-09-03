<?php

namespace App\Filament\Workshop\Resources\Roles\Pages;

use App\Filament\Workshop\Resources\Roles\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['panel'] = 'workshop';

        return $data;
    }
}
