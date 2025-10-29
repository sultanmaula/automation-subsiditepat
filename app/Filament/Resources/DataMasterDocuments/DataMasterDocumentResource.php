<?php

namespace App\Filament\Resources\DataMasterDocuments;

use App\Filament\Resources\DataMasterDocuments\Pages\CreateDataMasterDocument;
use App\Filament\Resources\DataMasterDocuments\Pages\EditDataMasterDocument;
use App\Filament\Resources\DataMasterDocuments\Pages\ListDataMasterDocuments;
use App\Filament\Resources\DataMasterDocuments\Schemas\DataMasterDocumentForm;
use App\Filament\Resources\DataMasterDocuments\Tables\DataMasterDocumentsTable;
use App\Models\DataMasterDocument;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DataMasterDocumentResource extends Resource
{
    protected static ?string $model = DataMasterDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?string $navigationLabel = 'Data Master Documents';

    protected static ?string $modelLabel = 'Data Master Documents';

    protected static ?string $pluralModelLabel = 'Data Master Documents';

    public static function form(Schema $schema): Schema
    {
        return DataMasterDocumentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DataMasterDocumentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDataMasterDocuments::route('/'),
            // 'create' => CreateDataMasterDocument::route('/create'),
            // 'edit' => EditDataMasterDocument::route('/{record}/edit'),
        ];
    }
}
