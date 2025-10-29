<?php

namespace App\Filament\Resources\DataNikInputs;

use App\Filament\Resources\DataNikInputs\Pages\CreateDataNikInput;
use App\Filament\Resources\DataNikInputs\Pages\EditDataNikInput;
use App\Filament\Resources\DataNikInputs\Pages\ListDataNikInputs;
use App\Filament\Resources\DataNikInputs\Schemas\DataNikInputForm;
use App\Filament\Resources\DataNikInputs\Tables\DataNikInputsTable;
use App\Models\DataNikInput;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DataNikInputResource extends Resource
{
    protected static ?string $model = DataNikInput::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Data NIK Input';

    protected static ?string $modelLabel = 'Data NIK Input';

    protected static ?string $pluralModelLabel = 'Data NIK Input';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return DataNikInputForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DataNikInputsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDataNikInputs::route('/'),
            'create' => CreateDataNikInput::route('/create'),
            'edit' => EditDataNikInput::route('/{record}/edit'),
        ];
    }
}
