<?php

namespace App\Filament\Resources\DataNikInputs\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DataNikInputForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nik')
                    ->label('NIK')
                    ->required()
                    ->maxLength(32)
                    ->unique(table: 'data_nik_inputs', column: 'nik', ignoreRecord: true),
                TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255),
                Textarea::make('address')
                    ->label('Address')
                    ->rows(4)
                    ->maxLength(65535),
                Select::make('data_master_document_id')
                    ->label('Document')
                    ->preload()
                    ->searchable()
                    ->relationship('document', 'original_name')
                    ->required(),
            ]);
    }
}
