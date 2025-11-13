<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Jobs\VerifyNikTransactionJob;
use App\Models\Account;
use App\Models\DataMasterDocument;
use App\Models\DataNikInput;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class AccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('last_update_api')
                    ->label('Last Update API')
                    ->placeholder('-')
                    ->dateTime(),
                TextColumn::make('bearer_token_status')
                    ->label('Bearer Token Status')
                    ->state(fn (Account $record): string => Cache::has("merchant_api_token_{$record->email}") ? 'Valid' : 'Expired')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Valid' ? 'success' : 'danger'),
                TextColumn::make('dataNikInput.name')
                    ->label('Last Input')
                    ->placeholder('-')
                    ->description(static fn (Account $record): ?string => $record->dataNikInput?->nik)
                    ->description(static fn (Account $record): ?string => 'NIK: ' . $record->dataNikInput?->nik . ' | File: ' . $record->dataNikInput?->document?->original_name),
            ])
            ->recordUrl(false)
            ->filters([
                //
            ])
            ->recordActions([
                ActionGroup::make([
                    // Action::make('fetchNewToken')
                    //     ->label('Fetch New Token')
                    //     ->icon('heroicon-s-arrow-path')
                    //     ->requiresConfirmation()
                    //     ->modalHeading('Fetch new Token from Subsidi Tepat LPG?')
                    //     ->hiddenLabel()
                    //     ->extraAttributes(['x-tooltip.raw' => 'Fetch New Token'])
                    //     ->color('info')
                    //     ->action(function (Account $record): void {
                    //         $exitCode = Artisan::call('merchant:fetch-token', [
                    //             '--email' => $record->email,
                    //             '--pin'   => $record->pin,
                    //         ]);
    
                    //         if ($exitCode === Command::SUCCESS) {
                    //             $record->update([
                    //                 'last_update_api' => now(),
                    //             ]);
    
                    //             Notification::make()
                    //                 ->title('Bearer Token API successfully updated.')
                    //                 ->success()
                    //                 ->send();
                    //         } elseif ($exitCode === Command::FAILURE) {
                    //             Notification::make()
                    //                 ->title('Failed to Fetch Bearer Token API.')
                    //                 ->danger()
                    //                 ->send();
                    //         }
                    //     }),
                    Action::make('infoAccount')
                        ->label('Info Account')
                        ->icon('heroicon-s-information-circle')
                        ->hiddenLabel()
                        ->extraAttributes(['x-tooltip.raw' => 'Info Account'])
                        ->color('warning')
                        ->slideOver()
                        ->modalHeading('Info Account')
                        ->modalSubmitAction(false)
                        ->form([
                            TextInput::make('storeName')
                                ->label('Store Name')
                                ->disabled(),
                            TextInput::make('stockAvailable')
                                ->label('Stock Available')
                                ->disabled(),
                            TextInput::make('stockDate')
                                ->label('Stock Date')
                                ->disabled(),
                            TextInput::make('lastSyncAt')
                                ->label('Last Sync At')
                                ->disabled(),
                        ])
                        ->beforeFormFilled(function (Action $action, Account $record): void {
                            if (!Cache::get("merchant_api_token_{$record->email}"))
                                Artisan::call('merchant:fetch-token', [
                                    '--email' => $record->email,
                                    '--pin'   => $record->pin,
                                ]);

                            $token = Cache::get("merchant_api_token_{$record->email}");
                            $res = Http::withHeaders([
                                'Authorization' => 'Bearer ' . $token,
                            ])->get('https://api-map.my-pertamina.id/general/products/v1/products/user');

                            if ($res->successful() && $res['code'] == 200 && $res['status'] == 'OK') {
                                $data = $res['data'];

                                $action->fillForm([
                                    'storeName' => $data['storeName'] ?? '',
                                    'stockAvailable' => $data['stockAvailable'] ?? 0,
                                    'stockDate' => $data['stockDate'] ?? '',
                                    'lastSyncAt' => $data['lastSyncAt'] ?? '',
                                ]);
                            } else {
                                Notification::make()
                                    ->title('Bearer Token expired. Please Fetch first!')
                                    ->danger()
                                    ->send();

                                Cache::forget("merchant_api_token_{$record->email}");
                                $action->cancel();
                            }
                        }),
                    Action::make('inputData')
                        ->label('Input Data')
                        ->icon('heroicon-s-paper-airplane')
                        ->hiddenLabel()
                        ->extraAttributes(['x-tooltip.raw' => 'Input Data'])
                        ->color('success')
                        ->slideOver()
                        ->modalHeading('Input Data')
                        ->steps([
                            Step::make('Select Mode')
                                ->schema([
                                    ViewField::make('job_loading_indicator')
                                        ->view('filament.components.job-loading-indicator'),
                                    Select::make('input_mode')
                                        ->label('Input Mode')
                                        ->placeholder('-')
                                        ->options([
                                            'manual' => 'Upload Manual',
                                            'document' => 'By Data Master Document',
                                            'document_manual_nik' => 'By Document (Select NIK Manually)',
                                        ])
                                        ->required()
                                        ->live(),
                                ]),
                            Step::make('Detail Input')
                                ->schema([
                                    TextInput::make('manual_nik')
                                        ->label('NIK Manual')
                                        ->placeholder('Enter NIK')
                                        ->minLength(16)
                                        ->maxLength(16)
                                        ->visible(fn (Get $get): bool => $get('input_mode') === 'manual')
                                        ->required(fn (Get $get): bool => $get('input_mode') === 'manual'),
                                    Select::make('data_master_document_id')
                                        ->label('Select File')
                                        ->placeholder('-')
                                        ->options(static fn (): array => DataMasterDocument::query()
                                            ->orderBy('original_name')
                                            ->pluck('original_name', 'id')
                                            ->toArray())
                                        ->searchable()
                                        ->preload()
                                        ->live()
                                        ->visible(fn (Get $get): bool => in_array($get('input_mode'), ['document', 'document_manual_nik'], true))
                                        ->required(fn (Get $get): bool => in_array($get('input_mode'), ['document', 'document_manual_nik'], true)),
                                    Select::make('data_nik_input_id')
                                        ->label('Select NIK (sort by order)')
                                        ->placeholder('-')
                                        ->options(function (Get $get): array {
                                            $documentId = $get('data_master_document_id');

                                            if (! $documentId) {
                                                return [];
                                            }

                                            return DataNikInput::query()
                                                ->where('data_master_document_id', $documentId)
                                                ->orderBy('order')
                                                ->get()
                                                ->mapWithKeys(fn (DataNikInput $input) => [
                                                    $input->id => sprintf('#%d - %s', $input->order, $input->nik),
                                                ])
                                                ->toArray();
                                        })
                                        ->searchable()
                                        ->live()
                                        ->visible(fn (Get $get): bool => $get('input_mode') === 'document_manual_nik')
                                        ->required(fn (Get $get): bool => $get('input_mode') === 'document_manual_nik'),
                                    TextInput::make('last_nik_input')
                                        ->label('Last NIK Input')
                                        ->placeholder('-')
                                        ->disabled(),
                                ]),
                        ])
                        ->beforeFormFilled(function (Action $action, Account $record): void {
                            $documentName = $record->dataNikInput?->document?->original_name;

                            $action->fillForm([
                                'last_nik_input' => $record->last_nik_input
                                    ? sprintf('%s | Dokumen: %s', $record->last_nik_input, $documentName ?? '-')
                                    : null,
                            ]);
                        })
                        ->action(function (Account $record, array $data): void {
                            $inputMode = $data['input_mode'] ?? null;

                            if ($inputMode === 'manual') {
                                try {
                                    VerifyNikTransactionJob::dispatchSync($record, $data['manual_nik']);
                                } catch (Throwable $exception) {
                                    $message = json_decode(trim($exception->getMessage()));

                                    Notification::make()
                                        ->title('Gagal memproses NIK manual.')
                                        ->body($message !== '' ? $message->message : null)
                                        ->danger()
                                        ->send();

                                    return;
                                }

                                Notification::make()
                                    ->title('Berhasil memproses NIK manual.')
                                    ->success()
                                    ->send();

                                return;
                            }

                            if (! in_array($inputMode, ['document', 'document_manual_nik'], true)) {
                                Notification::make()
                                    ->title('Metode input belum dipilih.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $documentId = $data['data_master_document_id'] ?? null;
                            $isManualNikSelection = $inputMode === 'document_manual_nik';

                            if (! $documentId) {
                                Notification::make()
                                    ->title('Silakan pilih dokumen terlebih dahulu.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $startingOrder = null;

                            if ($isManualNikSelection) {
                                $selectedNikId = $data['data_nik_input_id'] ?? null;

                                if (! $selectedNikId) {
                                    Notification::make()
                                        ->title('Silakan pilih NIK yang ingin diproses.')
                                        ->danger()
                                        ->send();

                                    return;
                                }

                                $selectedNik = DataNikInput::query()
                                    ->whereKey($selectedNikId)
                                    ->where('data_master_document_id', $documentId)
                                    ->first();

                                if (! $selectedNik) {
                                    Notification::make()
                                        ->title('Data NIK tidak ditemukan pada dokumen terpilih.')
                                        ->danger()
                                        ->send();

                                    return;
                                }

                                $startingOrder = (int) $selectedNik->order;
                            } else {
                                $lastNikValue = $record->last_nik_input;

                                if (! $lastNikValue) {
                                    Notification::make()
                                        ->title('Belum ada riwayat NIK terakhir untuk akun ini.')
                                        ->body('Silakan pilih NIK secara manual.')
                                        ->danger()
                                        ->send();

                                    return;
                                }

                                $lastNikRecord = DataNikInput::query()
                                    ->where('data_master_document_id', $documentId)
                                    ->where('nik', $lastNikValue)
                                    ->first();

                                if (! $lastNikRecord) {
                                    Notification::make()
                                        ->title('NIK terakhir tidak ditemukan pada dokumen ini.')
                                        ->body('Silakan pilih NIK secara manual.')
                                        ->danger()
                                        ->send();

                                    return;
                                }

                                $startingOrder = ((int) $lastNikRecord->order) + 1;
                            }

                            $niks = DataNikInput::query()
                                ->where('data_master_document_id', $documentId)
                                ->where('order', '>=', $startingOrder)
                                ->orderBy('order')
                                ->pluck('nik');

                            if ($niks->isEmpty()) {
                                Notification::make()
                                    ->title('Tidak ada NIK yang bisa diproses.')
                                    ->body($isManualNikSelection
                                        ? 'Silakan pilih NIK lainnya.'
                                        : 'Semua NIK pada dokumen ini telah diproses.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            foreach ($niks as $nik) {
                                try {
                                    VerifyNikTransactionJob::dispatchSync($record, $nik);
                                } catch (Throwable $exception) {
                                    $message = trim($exception->getMessage());
                                    $decoded = json_decode($message);

                                    if ($decoded && ($decoded->code ?? 0) >= 400) {
                                        continue;
                                    }

                                    Notification::make()
                                        ->title("Gagal memproses NIK {$nik}")
                                        ->body($message !== '' ? $message : null)
                                        ->danger()
                                        ->send();

                                    return;
                                }
                            }

                            Notification::make()
                                ->title('Berhasil memproses data NIK terpilih.')
                                ->success()
                                ->send();
                        }),
                    ViewAction::make()
                        ->extraAttributes(['x-tooltip.raw' => 'View Account'])
                        ->slideOver()
                        ->hiddenLabel(),
                    DeleteAction::make()
                        ->extraAttributes(['x-tooltip.raw' => 'Delete Account'])
                        ->hiddenLabel(),
                ])
                ->buttonGroup(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
