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
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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
                        ->form([
                            Select::make('data_master_document_id')
                                ->label('Pilih File')
                                ->placeholder('Pilih dokumen')
                                ->options(static fn (): array => DataMasterDocument::query()
                                    ->orderBy('original_name')
                                    ->pluck('original_name', 'id')
                                    ->toArray())
                                ->searchable()
                                ->preload()
                                ->required(),
                            TextInput::make('last_nik_input')
                                ->label('Last NIK Input')
                                ->placeholder('-')
                                ->disabled(),
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
                            if ($data['data_master_document_id'] === $record->dataNikInput?->data_master_document_id) {
                                // $niks = DataNikInput::where('order', '>', $record->dataNikInput?->order)->get()->pluck('nik');
                                $niks = DataNikInput::where('order', '>', 293)->where('order', '<', 299)->get()->pluck('nik');

                                foreach ($niks as $nik) {
                                    VerifyNikTransactionJob::dispatch($record, $nik);
                                }
                            } else {
                                Notification::make()
                                    ->title('Error!')
                                    ->danger()
                                    ->send();
                            }
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
