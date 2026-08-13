<?php

namespace App\Filament\Resources\AccountDocumentOrders;

use App\Filament\Resources\AccountDocumentOrders\Pages\ListAccountDocumentOrders;
use App\Filament\Resources\AccountDocumentOrders\Schemas\AccountDocumentOrderForm;
use App\Filament\Resources\AccountDocumentOrders\Tables\AccountDocumentOrdersTable;
use App\Models\AccountDocumentOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AccountDocumentOrderResource extends Resource
{
    protected static ?string $model = AccountDocumentOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'AccountDocumentOrderResource';

    public static function form(Schema $schema): Schema
    {
        return AccountDocumentOrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountDocumentOrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountDocumentOrders::route('/'),
        ];
    }
}
