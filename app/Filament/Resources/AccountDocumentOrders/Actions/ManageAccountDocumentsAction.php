<?php

namespace App\Filament\Resources\AccountDocumentOrders\Actions;

use App\Models\Account;
use App\Models\AccountDocumentOrder;
use App\Models\DataMasterDocument;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\DB;

class ManageAccountDocumentsAction
{
    /**
     * Tombol header: pilih akun bebas lalu atur dokumen & urutannya.
     */
    public static function header(): Action
    {
        return static::base('manageDocuments')
            ->label('Kelola Dokumen Akun')
            ->icon('heroicon-m-rectangle-stack')
            ->form([
                Select::make('account_id')
                    ->label('Account')
                    ->options(Account::pluck('email', 'id'))
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set): void {
                        $set('documents', static::existingDocuments($state));
                    }),

                static::documentsField(),
            ]);
    }

    /**
     * Aksi per baris (Edit): akun terkunci sesuai record, dokumen ter-load.
     * Modal & perilaku sama persis dengan tombol "Kelola Dokumen Akun".
     */
    public static function row(): Action
    {
        return static::base('edit')
            ->label('Edit')
            ->icon('heroicon-m-pencil-square')
            ->color('primary')
            ->fillForm(fn (AccountDocumentOrder $record): array => [
                'account_id' => $record->account_id,
                'documents' => static::existingDocuments($record->account_id),
            ])
            ->form([
                Select::make('account_id')
                    ->label('Account')
                    ->options(Account::pluck('email', 'id'))
                    ->disabled()
                    ->dehydrated()
                    ->required(),

                static::documentsField(),
            ]);
    }

    /**
     * Konfigurasi dasar yang dipakai bersama (heading modal + handler simpan).
     */
    protected static function base(string $name): Action
    {
        return Action::make($name)
            ->modalHeading('Kelola Dokumen Akun')
            ->modalDescription('Pilih dokumen. Urutan mengikuti urutan pemilihan (dokumen pertama = urutan 1).')
            ->modalSubmitActionLabel('Simpan')
            ->action(function (array $data): void {
                static::sync((int) $data['account_id'], array_values($data['documents']));
            });
    }

    protected static function documentsField(): Select
    {
        return Select::make('documents')
            ->label('Dokumen')
            ->options(DataMasterDocument::pluck('original_name', 'id'))
            ->multiple()
            ->searchable()
            ->preload()
            ->required()
            ->helperText('Urutan dokumen mengikuti urutan saat dipilih. Bisa diatur ulang lewat drag & drop di tabel.');
    }

    /**
     * Dokumen yang sudah terdaftar untuk akun (urut sesuai order saat ini).
     *
     * @return array<int, int>
     */
    protected static function existingDocuments(?int $accountId): array
    {
        if (! $accountId) {
            return [];
        }

        return AccountDocumentOrder::where('account_id', $accountId)
            ->orderBy('order')
            ->pluck('data_master_document_id')
            ->all();
    }

    /**
     * Sinkronkan dokumen akun: hapus semua urutan lama, buat ulang sesuai pilihan.
     *
     * @param  array<int, int>  $documentIds
     */
    protected static function sync(int $accountId, array $documentIds): void
    {
        DB::transaction(function () use ($accountId, $documentIds): void {
            AccountDocumentOrder::where('account_id', $accountId)->delete();

            foreach ($documentIds as $index => $documentId) {
                AccountDocumentOrder::create([
                    'account_id' => $accountId,
                    'data_master_document_id' => $documentId,
                    'order' => $index + 1,
                ]);
            }
        });

        Notification::make()
            ->title('Dokumen akun berhasil diperbarui')
            ->body(count($documentIds) . ' dokumen tersimpan dengan urutan baru.')
            ->success()
            ->send();
    }
}
