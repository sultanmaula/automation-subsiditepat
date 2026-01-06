<?php

namespace App\Filament\Workshop\Resources\Categories\Pages;

use App\Filament\Workshop\Resources\Categories\CategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;
}
