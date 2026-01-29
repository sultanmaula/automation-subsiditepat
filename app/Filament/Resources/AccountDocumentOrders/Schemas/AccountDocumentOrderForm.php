<?php

namespace App\Filament\Resources\AccountDocumentOrders\Schemas;

use App\Models\Account;
use App\Models\DataMasterDocument;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AccountDocumentOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('account_id')
                    ->label('Account')
                    ->options(Account::pluck('email', 'id'))
                    ->searchable()
                    ->required(),

                Select::make('data_master_document_id')
                    ->label('Data Master Document')
                    ->options(DataMasterDocument::pluck('original_name', 'id'))
                    ->searchable()
                    ->required(),

                TextInput::make('order')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->helperText('Urutan dokumen untuk akun ini (mulai dari 1)'),
            ]);
    }
}
