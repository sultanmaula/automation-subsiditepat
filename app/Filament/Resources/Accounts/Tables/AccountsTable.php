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
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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
                    ->label  ('Bearer Token Status')
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
                    Action::make('salesReport')
                        ->label('Rekap Penjualan')
                        ->icon('heroicon-s-chart-bar')
                        ->hiddenLabel()
                        ->extraAttributes(['x-tooltip.raw' => 'Rekap Penjualan'])
                        ->color('info')
                        ->modalHeading('Rekap Penjualan')
                        ->modalWidth('4xl')
                        ->modalSubmitActionLabel('Tampilkan Rekap')
                        ->beforeFormFilled(function (Action $action, Account $record): void {
                            $start = now()->startOfDay();
                            $end = now()->endOfDay();

                            if (! self::loadSalesReport($action, $record, $start, $end)) {
                                $action->cancel();
                            }
                        })
                        ->form([
                            Grid::make(2)
                                ->schema([
                                    DatePicker::make('report_start_date')
                                        ->label('Tanggal Mulai')
                                        ->default(fn (): string => now()->toDateString())
                                        ->maxDate(fn (Get $get): ?string => $get('report_end_date'))
                                        ->required(),
                                    DatePicker::make('report_end_date')
                                        ->label('Tanggal Selesai')
                                        ->default(fn (): string => now()->toDateString())
                                        ->minDate(fn (Get $get): ?string => $get('report_start_date'))
                                        ->required(),
                                ]),
                            ViewField::make('report_summary')
                                ->view('filament.components.sales-report-summary')
                                ->viewData(fn (Get $get, $state): array => [
                                    'summary' => $state ?? [],
                                    'dateRange' => [
                                        'start' => $get('report_start_date'),
                                        'end' => $get('report_end_date'),
                                    ],
                                ])
                                ->columnSpanFull()
                                ->dehydrated(false),
                            ViewField::make('report_customers')
                                ->view('filament.components.sales-report-customers')
                                ->viewData(fn ($state): array => [
                                    'customers' => $state ?? [],
                                ])
                                ->columnSpanFull()
                                ->dehydrated(false),
                        ])
                        ->action(function (Action $action, Account $record, array $data): void {
                            $startDate = $data['report_start_date'] ?? null;
                            $endDate = $data['report_end_date'] ?? null;

                            if (! $startDate || ! $endDate) {
                                Notification::make()
                                    ->title('Silakan pilih rentang tanggal terlebih dahulu.')
                                    ->warning()
                                    ->send();

                                $action->halt();

                                return;
                            }

                            $start = Carbon::parse($startDate)->startOfDay();
                            $end = Carbon::parse($endDate)->endOfDay();

                            if ($start->greaterThan($end)) {
                                Notification::make()
                                    ->title('Tanggal mulai tidak boleh lebih besar dari tanggal selesai.')
                                    ->danger()
                                    ->send();

                                $action->halt();

                                return;
                            }

                            if (! self::loadSalesReport($action, $record, $start, $end, true)) {
                                $action->halt();

                                return;
                            }

                            $action->halt();
                        }),
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
                            if (!Cache::get("merchant_api_token_{$record->email}") || Cache::get("merchant_api_token_{$record->email}") == NULL)
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
                                $exitCode = Artisan::call('merchant:verify-nik', [
                                    'account' => $record->email,
                                    'nik'     => $data['manual_nik'],
                                ]);

                                if ($exitCode !== Command::SUCCESS) {
                                    $output = trim(Artisan::output());
                                    $message = json_decode($output);
                                    
                                    if ($message->message === 'Transaksi melebihi stok yang dapat dijual' && $message->code === 400) {
                                        Notification::make()
                                            ->title('Stok hari ini sudah ter-input semua.')
                                            ->success()
                                            ->send();

                                        return;
                                    }

                                    Notification::make()
                                        ->title('Gagal memproses NIK manual.')
                                        ->body($message ? ($message->message ?? $output) : $output)
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
                                $exitCode = Artisan::call('merchant:verify-nik', [
                                    'account' => $record->email,
                                    'nik'     => $nik,
                                ]);

                                if ($exitCode !== Command::SUCCESS) {
                                    $output = trim(Artisan::output());
                                    $decoded = json_decode($output);
                                    
                                    if ($decoded?->message === 'Transaksi melebihi stok yang dapat dijual' && $decoded?->code === 400) {
                                        Notification::make()
                                            ->title('Stok hari ini sudah ter-input semua.')
                                            ->success()
                                            ->send();

                                        return;
                                    }

                                    if (\Str::startsWith($output, "Verify-NIK request failed") || ($decoded && ($decoded->code ?? 0) >= 400)) {
                                        usleep(3000000);
                                        continue;
                                    }

                                    Notification::make()
                                        ->title("Gagal memproses NIK {$nik}")
                                        ->body($output !== '' ? $output : null)
                                        ->danger()
                                        ->send();

                                    return;
                                }
                                usleep(3000000);
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

    protected static function loadSalesReport(Action $action, Account $record, Carbon $start, Carbon $end, bool $notifySuccess = false): bool
    {
        if (! Cache::get("merchant_api_token_{$record->email}") || Cache::get("merchant_api_token_{$record->email}") == NULL) {
            Artisan::call('merchant:fetch-token', [
                '--email' => $record->email,
                '--pin' => $record->pin,
            ]);
        }

        $token = Cache::get("merchant_api_token_{$record->email}");

        if (! $token) {
            Notification::make()
                ->title('Bearer token tidak tersedia, silakan coba lagi.')
                ->danger()
                ->send();

            return false;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->get('https://api-map.my-pertamina.id/general/v3/transactions/report', [
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
        ]);

        if ($response->status() === 401) {
            Cache::forget("merchant_api_token_{$record->email}");

            Notification::make()
                ->title('Bearer token kedaluwarsa, silakan perbarui terlebih dahulu.')
                ->danger()
                ->send();

            return false;
        }

        if ($response->failed()) {
            Notification::make()
                ->title('Gagal mengambil rekap penjualan.')
                ->body($response->json('message') ?? $response->body())
                ->danger()
                ->send();

            return false;
        }

        $payload = $response->json();

        if (($payload['code'] ?? null) !== 200 || ($payload['status'] ?? null) !== 'OK') {
            Notification::make()
                ->title('Rekap penjualan tidak tersedia.')
                ->body($payload['message'] ?? 'Terjadi kesalahan saat membaca data.')
                ->danger()
                ->send();

            return false;
        }

        $dataPayload = $payload['data'] ?? [];

        $summaryReport = data_get($dataPayload, 'summaryReport', []);

        $summary = [
            'sold' => data_get($summaryReport, 'sold'),
            'modal' => data_get($summaryReport, 'modal'),
            'profit' => data_get($summaryReport, 'profit'),
            'gross' => data_get($summaryReport, 'gross'),
        ];

        $customers = collect(data_get($dataPayload, 'customersReport', []))
            ->map(fn ($customer) => [
                'customer_report_id' => data_get($customer, 'customerReportId'),
                'nationality_id' => data_get($customer, 'nationalityId'),
                'name' => data_get($customer, 'name'),
                'categories' => collect(data_get($customer, 'categories', []))
                    ->map(fn ($category) => data_get($category, 'name') ?? (is_string($category) ? $category : null))
                    ->filter()
                    ->values()
                    ->all(),
                'total' => data_get($customer, 'total'),
            ])
            ->values()
            ->all();

        $action->fillForm([
            'report_start_date' => $start->toDateString(),
            'report_end_date' => $end->toDateString(),
            'report_summary' => $summary,
            'report_customers' => $customers,
        ]);

        if ($notifySuccess) {
            Notification::make()
                ->title('Rekap penjualan berhasil dimuat.')
                ->success()
                ->send();
        }

        return true;
    }
}
