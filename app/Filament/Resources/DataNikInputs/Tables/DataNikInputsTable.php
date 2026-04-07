<?php

namespace App\Filament\Resources\DataNikInputs\Tables;

use App\Models\DataMasterDocument;
use App\Models\DataNikInput;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class DataNikInputsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order')
                    ->label('Order')
                    ->sortable(),
                TextColumn::make('nik')
                    ->label('NIK')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('NIK copied')
                    ->copyableState(static fn (?string $state): ?string => $state),
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable(),
                TextColumn::make('address')
                    ->label('Address')
                    ->wrap(),
                TextColumn::make('document.original_name')
                    ->label('Document Name')
                    ->toggleable()
                    ->searchable()
                    ->placeholder('-'),
            ])
            ->deferFilters(false)
            ->filters([
                SelectFilter::make('data_master_document_id')
                    ->label('Document')
                    ->options(
                        DataMasterDocument::query()
                            ->pluck('original_name', 'id')
                            ->toArray()
                    )
                    ->searchable()
                    ->placeholder('All Documents'),
            ])
            ->recordActions([
                EditAction::make()->slideOver(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
