<?php

namespace App\Filament\Workshop\Resources\Categories\Pages;

use App\Filament\Workshop\Resources\Categories\CategoryResource;
use Filament\Resources\Pages\EditRecord;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;
}
