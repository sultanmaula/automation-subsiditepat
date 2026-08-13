<?php

namespace App\Filament\Resources\AccountDocumentOrders\Schemas;

use App\Models\Account;
use App\Models\AccountDocumentOrder;
use App\Models\DataMasterDocument;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class AccountDocumentOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Urutan Dokumen Akun')
                    ->description('Pilih akun lalu dokumen. Nomor urut terisi otomatis berdasarkan urutan terakhir akun.')
                    ->columns(2)
                    ->components([
                        Select::make('account_id')
                            ->label('Account')
                            ->options(Account::pluck('email', 'id'))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $next = $state
                                    ? (AccountDocumentOrder::where('account_id', $state)->max('order') ?? 0) + 1
                                    : 1;
                                $set('order', $next);
                            }),

                        Select::make('data_master_document_id')
                            ->label('Data Master Document')
                            ->options(DataMasterDocument::pluck('original_name', 'id'))
                            ->searchable()
                            ->preload()
                            ->required(),

                        TextInput::make('order')
                            ->label('Urutan')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->default(1)
                            ->helperText('Terisi otomatis saat akun dipilih (urutan terakhir + 1). Bisa diubah manual.'),
                    ]),
            ]);
    }
}
