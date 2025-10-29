<?php

namespace App\Filament\Resources\DataMasterDocuments\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class DataMasterDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('original_name')
                    ->label('Docs Name')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Nama disalin'),
                TextColumn::make('stored_name')
                    ->label('Stored Name')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('disk')
                    ->label('Disk')
                    ->badge(),
                TextColumn::make('path')
                    ->label('Path')
                    ->wrap()
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('extension')
                    ->label('Extension')
                    ->toggleable(),
                TextColumn::make('mime_type')
                    ->label('MIME Type')
                    ->toggleable(),
                TextColumn::make('size')
                    ->label('Size')
                    ->formatStateUsing(static fn (?int $state): ?string => $state ? Str::of(number_format($state / 1024, 2))->append(' KB')->toString() : null)
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordUrl(false)
            ->filters([
                //
            ])
            ->recordActions([
                //
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
