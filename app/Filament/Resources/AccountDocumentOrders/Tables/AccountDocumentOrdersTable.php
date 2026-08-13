<?php

namespace App\Filament\Resources\AccountDocumentOrders\Tables;

use App\Filament\Resources\AccountDocumentOrders\Actions\ManageAccountDocumentsAction;
use App\Models\Account;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;

class AccountDocumentOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order')
                    ->label('Urutan')
                    ->badge()
                    ->color('primary')
                    ->sortable(),

                TextColumn::make('account.email')
                    ->label('Account')
                    ->icon('heroicon-m-user-circle')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('document.original_name')
                    ->label('Document')
                    ->icon('heroicon-m-document-text')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('order')
            ->groups([
                Group::make('account.email')
                    ->label('Account')
                    ->collapsible(),
            ])
            ->defaultGroup('account.email')
            // Drag & drop hanya aktif saat satu akun difilter, agar penomoran
            // ulang 1..N benar per-akun (lihat plan: reorderTable menomori global).
            ->reorderable(
                'order',
                condition: fn (HasTable $livewire): bool =>
                    filled(data_get($livewire->getTableFilterState('account_id'), 'value')),
            )
            ->filters([
                SelectFilter::make('account_id')
                    ->label('Account')
                    ->options(Account::pluck('email', 'id'))
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                ManageAccountDocumentsAction::row(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
