<?php

namespace App\Filament\Resources\DataMasterDocuments\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DataMasterDocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('original_name')
                    ->label('Docs Name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('stored_name')
                    ->label('Stored Name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('path')
                    ->label('Path')
                    ->required()
                    ->maxLength(255),
                Select::make('disk')
                    ->label('Disk')
                    ->options(collect(config('filesystems.disks'))
                        ->keys()
                        ->mapWithKeys(fn ($disk) => [$disk => $disk])
                        ->toArray())
                    ->required(),
                TextInput::make('extension')
                    ->label('Extension')
                    ->maxLength(16),
                TextInput::make('mime_type')
                    ->label('MIME Type')
                    ->maxLength(255),
                TextInput::make('size')
                    ->label('Size (byte)')
                    ->numeric()
                    ->minValue(0),
                DateTimePicker::make('created_at')
                    ->label('Created At')
                    ->disabled()
                    ->dehydrated(false),
                DateTimePicker::make('updated_at')
                    ->label('Updated At')
                    ->disabled()
                    ->dehydrated(false),
            ]);
    }
}
