<?php

namespace App\Filament\Resources\AccountDocumentOrders\Tables;

use App\Filament\Resources\AccountDocumentOrders\Actions\ManageAccountDocumentsAction;
use App\Models\Account;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
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
            // Penomoran ulang ditangani ListAccountDocumentOrders::reorderTable()
            // yang menukar `order` per akun, jadi drag & drop aman dipakai
            // tanpa harus memfilter satu akun lebih dulu.
            ->reorderable('order')
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
