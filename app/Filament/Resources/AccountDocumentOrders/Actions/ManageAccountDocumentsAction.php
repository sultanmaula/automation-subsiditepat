<?php

namespace App\Filament\Resources\AccountDocumentOrders\Actions;

use App\Models\Account;
use App\Models\AccountDocumentOrder;
use App\Models\DataMasterDocument;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
                        // `$set` menulis langsung ke state internal Repeater, yang
                        // ber-key uuid — beda dengan array datar hasil dehydrate.
                        $set('documents', static::toRepeaterState(
                            static::existingDocuments($state),
                        ));
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
            // Beda dengan `$set` di atas: fillForm lewat proses hydration
            // Repeater, yang justru mengharapkan array datar.
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
            ->modalDescription('Susun dokumen dari atas ke bawah — yang paling atas dipakai lebih dulu (urutan 1).')
            ->modalSubmitActionLabel('Simpan')
            ->action(function (array $data): void {
                $documentIds = collect($data['documents'] ?? [])
                    ->filter(fn ($id): bool => filled($id))
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                static::sync((int) $data['account_id'], $documentIds);
            });
    }

    protected static function documentsField(): Repeater
    {
        return Repeater::make('documents')
            ->label('Dokumen')
            ->simple(
                Select::make('data_master_document_id')
                    ->label('Dokumen')
                    ->options(DataMasterDocument::pluck('original_name', 'id'))
                    ->searchable()
                    ->preload()
                    ->required()
                    // 1 akun tidak boleh punya dokumen yang sama dua kali
                    // (unique account_id + data_master_document_id di DB).
                    ->distinct()
                    ->fixIndistinctState(),
            )
            ->itemNumbers()
            ->reorderableWithButtons()
            ->addActionLabel('Tambah dokumen')
            ->defaultItems(0)
            ->minItems(1)
            ->columnSpanFull()
            ->helperText('Geser baris (drag & drop) atau pakai tombol panah untuk mengatur urutan. Nomor di kiri = urutan pemakaian file.');
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
     * Bungkus daftar id dokumen ke bentuk state internal Repeater.
     *
     * @param  array<int, int>  $documentIds
     * @return array<string, array{data_master_document_id: int}>
     */
    protected static function toRepeaterState(array $documentIds): array
    {
        $state = [];

        foreach ($documentIds as $documentId) {
            $state[(string) Str::uuid()] = ['data_master_document_id' => $documentId];
        }

        return $state;
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
