<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Exceptions\StockExhaustedException;
use App\Jobs\CancelDuplicateTransactionJob;
use App\Jobs\NotifyNikChainCompleted;
use App\Jobs\ProcessNikJob;
use App\Jobs\VerifyNikTransactionJob;
use App\Models\Account;
use App\Models\DataMasterDocument;
use App\Models\DataNikInput;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Component as LivewireComponent;
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
                    ->state(fn(Account $record): string => Cache::has("merchant_api_token_{$record->email}") ? 'Valid' : 'Expired')
                    ->badge()
                    ->color(fn(string $state): string => $state === 'Valid' ? 'success' : 'danger'),
                TextColumn::make('dataNikInput.name')
                    ->label('Last Input')
                    ->placeholder('-')
                    ->description(static fn(Account $record): ?string => $record->dataNikInput
                        ? 'NIK: ' . $record->dataNikInput?->nik . ' | File: ' . $record->dataNikInput?->document?->original_name
                        : null),
                ViewColumn::make('nik_progress')
                    ->label('Progress Input')
                    ->view('filament.tables.columns.nik-progress'),
            ])
            ->recordUrl(false)
            ->filters([
                //
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('toggleAutoInputNik')
                        ->label(fn(Account $record): string => $record->auto_input_nik ? 'Auto Input NIK: ON' : 'Auto Input NIK: OFF')
                        ->icon(fn(Account $record): string => $record->auto_input_nik ? 'heroicon-s-check-circle' : 'heroicon-s-x-circle')
                        ->hiddenLabel()
                        ->extraAttributes(fn(Account $record): array => [
                            'x-tooltip.raw' => $record->auto_input_nik ? 'Auto Input NIK: ON' : 'Auto Input NIK: OFF',
                        ])
                        ->color(fn(Account $record): string => $record->auto_input_nik ? 'success' : 'danger')
                        ->action(function (Account $record): void {
                            if ($record->auto_input_nik == false && (!Cache::get("merchant_api_token_{$record->email}") || Cache::get("merchant_api_token_{$record->email}") == NULL))
                                Artisan::call('merchant:fetch-token', [
                                    '--email' => $record->email,
                                    '--pin' => $record->pin,
                                ]);

                            $record->update([
                                'auto_input_nik' => !$record->auto_input_nik,
                            ]);

                            $status = $record->auto_input_nik ? 'diaktifkan' : 'dinonaktifkan';

                            Notification::make()
                                ->title("Auto Input NIK berhasil {$status}.")
                                ->success()
                                ->send();
                        }),
                    Action::make('salesReport')
                        ->label('Rekap Penjualan')
                        ->icon('heroicon-s-chart-bar')
                        ->hiddenLabel()
                        ->extraAttributes(['x-tooltip.raw' => 'Rekap Penjualan'])
                        ->color('info')
                        ->modalHeading('Rekap Penjualan')
                        ->modalWidth('4xl')
                        ->modalCancelActionLabel('Tutup')
                        ->mountUsing(function (Action $action, Account $record, $form): void {
                            $form->fill();

                            $start = now()->startOfDay();
                            $end = now()->endOfDay();

                            // Fetch initial data
                            $data = self::fetchSalesReportData($record, $start, $end);

                            // Fill form with initial data
                            $form->fill([
                                'report_start_date' => now()->toDateString(),
                                'report_end_date' => now()->toDateString(),
                                'summary_sold' => data_get($data, 'summary.sold'),
                                'summary_gross' => data_get($data, 'summary.gross'),
                                'summary_modal' => data_get($data, 'summary.modal'),
                                'summary_profit' => data_get($data, 'summary.profit'),
                                'report_customers' => data_get($data, 'customers', []),
                            ]);
                        })
                        ->form([
                            Grid::make(2)
                                ->schema([
                                    DatePicker::make('report_start_date')
                                        ->label('Tanggal Mulai')
                                        ->default(fn(): string => now()->toDateString())
                                        ->maxDate(fn(Get $get): ?string => $get('report_end_date'))
                                        ->required()
                                        ->live(),
                                    DatePicker::make('report_end_date')
                                        ->label('Tanggal Selesai')
                                        ->default(fn(): string => now()->toDateString())
                                        ->minDate(fn(Get $get): ?string => $get('report_start_date'))
                                        ->required()
                                        ->live(),
                                ]),

                            // Hidden fields to store reactive data
                            Hidden::make('summary_sold')->live()->dehydrated(false),
                            Hidden::make('summary_gross')->live()->dehydrated(false),
                            Hidden::make('summary_modal')->live()->dehydrated(false),
                            Hidden::make('summary_profit')->live()->dehydrated(false),
                            Hidden::make('report_customers')->live()->dehydrated(false),
                            Hidden::make('searched_nik')->live()->dehydrated(false),

                            // Filter untuk jumlah tabung dan NIK
                            Grid::make(3)
                                ->schema([
                                    Select::make('filter_total_tabung')
                                        ->label('Filter Jumlah Tabung')
                                        ->placeholder('Semua')
                                        ->options([
                                            '1' => '1 Tabung',
                                            '2' => '2 Tabung',
                                            '3' => '3 Tabung',
                                            '4' => '4 Tabung',
                                            '5' => '5 Tabung',
                                            '6' => '6 Tabung',
                                            '7' => '7 Tabung',
                                            '8' => '8 Tabung',
                                            '9' => '9 Tabung',
                                            '10' => '10 Tabung',
                                        ])
                                        ->live()
                                        ->dehydrated(false),
                                    TextInput::make('filter_nik')
                                        ->label('Cari NIK')
                                        ->placeholder('Masukkan NIK')
                                        ->dehydrated(false),
                                    \Filament\Schemas\Components\Actions::make([
                                        Action::make('searchNik')
                                            ->label('Cari NIK')
                                            ->icon('heroicon-o-magnifying-glass')
                                            ->action(function (Get $get, \Filament\Schemas\Components\Utilities\Set $set, Account $record): void {
                                                $filterNik = $get('filter_nik');
                                                $startDate = $get('report_start_date');
                                                $endDate = $get('report_end_date');

                                                if (!$filterNik || trim($filterNik) === '') {
                                                    Notification::make()
                                                        ->title('Masukkan NIK terlebih dahulu.')
                                                        ->warning()
                                                        ->send();
                                                    return;
                                                }

                                                if (!$startDate || !$endDate) {
                                                    Notification::make()
                                                        ->title('Silakan pilih rentang tanggal terlebih dahulu.')
                                                        ->warning()
                                                        ->send();
                                                    return;
                                                }

                                                $start = Carbon::parse($startDate)->startOfDay();
                                                $end = Carbon::parse($endDate)->endOfDay();

                                                // Fetch data with NIK search
                                                $reportData = self::fetchSalesReportData($record, $start, $end, $filterNik);

                                                // Update form data via Set
                                                $set('summary_sold', data_get($reportData, 'summary.sold'));
                                                $set('summary_gross', data_get($reportData, 'summary.gross'));
                                                $set('summary_modal', data_get($reportData, 'summary.modal'));
                                                $set('summary_profit', data_get($reportData, 'summary.profit'));
                                                $set('report_customers', data_get($reportData, 'customers', []));
                                                $set('filter_total_tabung', null);
                                                $set('searched_nik', $filterNik);

                                                $customerCount = count(data_get($reportData, 'customers', []));

                                                Notification::make()
                                                    ->title('Pencarian NIK selesai.')
                                                    ->body(sprintf('Ditemukan %d konsumen dengan NIK mengandung "%s"', $customerCount, $filterNik))
                                                    ->success()
                                                    ->send();
                                            }),
                                    ])->verticallyAlignEnd(),
                                ]),

                            // Summary Section with Filament components
                            Section::make('Ringkasan Penjualan')
                                ->icon('heroicon-o-chart-bar')
                                ->description(function (Get $get): string {
                                    $period = sprintf(
                                        'Periode: %s — %s',
                                        $get('report_start_date') ? Carbon::parse($get('report_start_date'))->format('d M Y') : '-',
                                        $get('report_end_date') ? Carbon::parse($get('report_end_date'))->format('d M Y') : '-'
                                    );

                                    $filters = [];
                                    if ($get('filter_total_tabung')) {
                                        $filters[] = $get('filter_total_tabung') . ' Tabung';
                                    }
                                    if ($get('searched_nik')) {
                                        $filters[] = 'NIK: ' . $get('searched_nik');
                                    }

                                    return $period . (count($filters) > 0 ? ' | Filter: ' . implode(', ', $filters) : '');
                                })
                                ->collapsible()
                                ->schema([
                                    Grid::make(4)
                                        ->schema([
                                            Placeholder::make('card_sold')
                                                ->label('')
                                                ->live()
                                                ->content(fn(Get $get): HtmlString => new HtmlString(
                                                    self::renderStatCard(
                                                        'Tabung Terjual',
                                                        self::formatNumber(self::getDisplaySold($get)),
                                                        'Unit terjual',
                                                        'heroicon-o-cube',
                                                        'emerald'
                                                    )
                                                )),
                                            Placeholder::make('card_gross')
                                                ->label('')
                                                ->live()
                                                ->content(fn(Get $get): HtmlString => new HtmlString(
                                                    self::renderStatCard(
                                                        'Omzet (Gross)',
                                                        self::formatCurrency(self::getDisplayGross($get)),
                                                        'Total pendapatan',
                                                        'heroicon-o-banknotes',
                                                        'blue'
                                                    )
                                                )),
                                            Placeholder::make('card_modal')
                                                ->label('')
                                                ->live()
                                                ->content(fn(Get $get): HtmlString => new HtmlString(
                                                    self::renderStatCard(
                                                        'Modal',
                                                        self::formatCurrency(self::getDisplayModal($get)),
                                                        'Total pengeluaran',
                                                        'heroicon-o-credit-card',
                                                        'amber'
                                                    )
                                                )),
                                            Placeholder::make('card_profit')
                                                ->label('')
                                                ->live()
                                                ->content(fn(Get $get): HtmlString => new HtmlString(
                                                    self::renderStatCard(
                                                        'Profit',
                                                        self::formatCurrency(self::getDisplayProfit($get)),
                                                        'Keuntungan bersih',
                                                        'heroicon-o-currency-dollar',
                                                        'purple'
                                                    )
                                                )),
                                        ]),
                                ]),

                            // Customers Section with Repeater
                            Section::make(fn(Get $get): string => sprintf(
                                'Daftar Konsumen (%d)',
                                count(self::filterCustomersByTotal($get('report_customers') ?? [], $get('filter_total_tabung')))
                            ))
                                ->icon('heroicon-o-user-group')
                                ->description('Detail transaksi per konsumen')
                                ->collapsible()
                                ->collapsed(fn(Get $get): bool => count($get('report_customers') ?? []) > 10)
                                ->schema([
                                    Placeholder::make('filtered_customers_list')
                                        ->label('')
                                        ->live()
                                        ->content(function (Get $get): HtmlString {
                                            $customers = $get('report_customers') ?? [];
                                            $filterTotal = $get('filter_total_tabung');

                                            $filtered = self::filterCustomersByTotal($customers, $filterTotal);

                                            if (empty($filtered)) {
                                                return new HtmlString('<p class="text-sm text-gray-500 dark:text-gray-400 italic">Tidak ada data konsumen.</p>');
                                            }

                                            return new HtmlString(self::renderCustomersList($filtered));
                                        }),
                                ]),
                        ])
                        ->modalSubmitActionLabel('Tampilkan Rekap')
                        ->action(function (Action $action, Account $record, array $data, LivewireComponent $livewire): void {
                            $startDate = $data['report_start_date'] ?? null;
                            $endDate = $data['report_end_date'] ?? null;

                            if (!$startDate || !$endDate) {
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

                            $reportData = self::fetchSalesReportData($record, $start, $end);

                            // Update form data via Livewire component's mountedActions
                            $mountedActionIndex = array_key_last($livewire->mountedActions);

                            if ($mountedActionIndex !== null) {
                                $livewire->mountedActions[$mountedActionIndex]['data']['summary_sold'] = data_get($reportData, 'summary.sold');
                                $livewire->mountedActions[$mountedActionIndex]['data']['summary_gross'] = data_get($reportData, 'summary.gross');
                                $livewire->mountedActions[$mountedActionIndex]['data']['summary_modal'] = data_get($reportData, 'summary.modal');
                                $livewire->mountedActions[$mountedActionIndex]['data']['summary_profit'] = data_get($reportData, 'summary.profit');
                                $livewire->mountedActions[$mountedActionIndex]['data']['report_customers'] = data_get($reportData, 'customers', []);
                                $livewire->mountedActions[$mountedActionIndex]['data']['filter_total_tabung'] = null;
                            }

                            Notification::make()
                                ->title('Rekap penjualan berhasil dimuat.')
                                ->body(sprintf('Periode: %s - %s', $start->format('d M Y'), $end->format('d M Y')))
                                ->success()
                                ->send();

                            $action->halt();
                        })
                        ->extraModalFooterActions([
                            Action::make('cancelMonthlyExcess')
                                ->label('Batalkan Kelebihan Bulanan')
                                ->color('warning')
                                ->requiresConfirmation()
                                ->modalHeading('Batalkan Kelebihan Transaksi Bulanan')
                                ->modalDescription(fn(): string => \sprintf(
                                    'Aksi ini akan mengambil data transaksi %s (%s s/d %s), lalu membatalkan kelebihan tabung untuk setiap konsumen yang memiliki >= 5 tabung — menyisakan maksimal 4 tabung per konsumen.',
                                    now()->translatedFormat('F Y'),
                                    now()->startOfMonth()->format('d M Y'),
                                    now()->endOfMonth()->format('d M Y'),
                                ))
                                ->modalSubmitActionLabel('Ya, Batalkan')
                                ->modalCancelActionLabel('Tidak')
                                ->action(function (Account $record): void {
                                    $start = now()->startOfMonth();
                                    $end = now()->endOfMonth();

                                    $reportData = self::fetchSalesReportData($record, $start, $end);
                                    $customers = $reportData['customers'] ?? [];

                                    if (empty($customers)) {
                                        Notification::make()
                                            ->title('Tidak ada data transaksi bulan ini.')
                                            ->warning()
                                            ->send();
                                        return;
                                    }

                                    $totalCancelled = 0;
                                    $affectedCustomers = 0;

                                    foreach ($customers as $customer) {
                                        $total = (int) ($customer['total'] ?? 0);
                                        $customerReportId = $customer['customer_report_id'] ?? null;

                                        if ($total >= 5 && $customerReportId) {
                                            $cancelCount = $total - 4;
                                            $affectedCustomers++;

                                            for ($i = 0; $i < $cancelCount; $i++) {
                                                Artisan::call('merchant:cancel-duplicate', [
                                                    'account' => $record->email,
                                                    'customerReportId' => $customerReportId,
                                                    'startDate' => $start->toDateString(),
                                                    'endDate' => $end->toDateString(),
                                                ]);
                                                $totalCancelled++;
                                            }
                                        }
                                    }

                                    if ($totalCancelled > 0) {
                                        Notification::make()
                                            ->title('Pembatalan kelebihan tabung selesai.')
                                            ->body(sprintf(
                                                '%d transaksi dibatalkan dari %d konsumen (bulan %s).',
                                                $totalCancelled,
                                                $affectedCustomers,
                                                now()->translatedFormat('F Y'),
                                            ))
                                            ->success()
                                            ->send();
                                    } else {
                                        Notification::make()
                                            ->title('Tidak ada transaksi yang perlu dibatalkan.')
                                            ->body('Tidak ada konsumen dengan >= 5 tabung bulan ini.')
                                            ->info()
                                            ->send();
                                    }
                                }),
                            Action::make('cancelTransaction')
                                ->label('Batalkan Transaksi')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->modalHeading('Batalkan Transaksi')
                                ->modalDescription('Apakah Anda yakin ingin membatalkan transaksi duplikat? Sistem akan membatalkan transaksi ganda dan hanya menyisakan 1 transaksi per konsumen.')
                                ->modalSubmitActionLabel('Ya, Batalkan')
                                ->modalCancelActionLabel('Tidak')
                                ->action(function (Account $record, LivewireComponent $livewire): void {
                                    // Get data from parent action's mounted data
                                    $parentActionIndex = array_key_first($livewire->mountedActions);
                                    $parentData = $livewire->mountedActions[$parentActionIndex]['data'] ?? [];

                                    $startDate = $parentData['report_start_date'] ?? null;
                                    $endDate = $parentData['report_end_date'] ?? null;
                                    $customers = $parentData['report_customers'] ?? [];

                                    if (!$startDate || !$endDate) {
                                        Notification::make()
                                            ->title('Silakan pilih rentang tanggal terlebih dahulu.')
                                            ->warning()
                                            ->send();
                                        return;
                                    }

                                    if ($startDate !== $endDate) {
                                        Notification::make()
                                            ->title('Rentang tanggal harus sama.')
                                            ->danger()
                                            ->send();
                                        return;
                                    }

                                    if (empty($customers)) {
                                        Notification::make()
                                            ->title('Tidak ada data konsumen untuk diproses.')
                                            ->warning()
                                            ->send();
                                        return;
                                    }

                                    // Dispatch job for each customer that has more than 1 transaction
                                    $dispatchedCount = 0;
                                    foreach ($customers as $customer) {
                                        $total = (int) ($customer['total'] ?? 0);
                                        $customerReportId = $customer['customer_report_id'] ?? null;

                                        if ($total > 1 && $customerReportId) {
                                            // CancelDuplicateTransactionJob::dispatch(
                                            //     $record,
                                            //     $customerReportId,
                                            //     $startDate,
                                            //     $endDate,
                                            // ); 

                                            Artisan::call('merchant:cancel-duplicate', [
                                                'account' => $record->email,
                                                'customerReportId' => $customerReportId,
                                                'startDate' => $startDate,
                                                'endDate' => $endDate,
                                            ]);
                                            $dispatchedCount++;
                                        }
                                    }

                                    if ($dispatchedCount > 0) {
                                        Notification::make()
                                            ->title('Proses pembatalan transaksi dimulai.')
                                            ->body(sprintf('%d konsumen dengan transaksi ganda akan diproses.', $dispatchedCount))
                                            ->success()
                                            ->send();
                                    } else {
                                        Notification::make()
                                            ->title('Tidak ada transaksi ganda yang perlu dibatalkan.')
                                            ->info()
                                            ->send();
                                    }
                                }),
                        ]),
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
                            $tokenBefore = Cache::get("merchant_api_token_{$record->email}");
                            Log::debug('[InfoAccount] token in cache before fetch: ' . ($tokenBefore ? 'YES ('.strlen($tokenBefore).' chars)' : 'NO'));

                            if (!$tokenBefore) {
                                $exitCode = Artisan::call('merchant:fetch-token', [
                                    '--email' => $record->email,
                                    '--pin' => $record->pin,
                                ]);
                                $artisanOutput = Artisan::output();
                                Log::debug('[InfoAccount] artisan exit code: ' . $exitCode . ', output: ' . $artisanOutput);
                            }

                            $token = Cache::get("merchant_api_token_{$record->email}");
                            Log::debug('[InfoAccount] token in cache after fetch: ' . ($token ? 'YES ('.strlen($token).' chars)' : 'NO'));

                            $res = Http::withHeaders([
                                'Authorization' => 'Bearer ' . $token,
                            ])->get('https://api-map.my-pertamina.id/general/products/v1/products/user');

                            Log::debug('[InfoAccount] API status: ' . $res->status() . ', body: ' . $res->body());

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
                    Action::make('lastInputLog')
                        ->label('Log Inputan')
                        ->icon('heroicon-s-clock')
                        ->hiddenLabel()
                        ->extraAttributes(['x-tooltip.raw' => 'Log Inputan Terakhir'])
                        ->color('gray')
                        ->slideOver()
                        ->modalHeading(fn(Account $record): string => 'Log Inputan: ' . $record->email)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Tutup')
                        ->form([
                            Placeholder::make('last_input_log')
                                ->hiddenLabel()
                                ->content(fn(Account $record): HtmlString => new HtmlString(self::renderLastInputLog($record))),
                        ]),
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
                                        ->visible(fn(Get $get): bool => $get('input_mode') === 'manual')
                                        ->required(fn(Get $get): bool => $get('input_mode') === 'manual'),
                                    Select::make('data_master_document_id')
                                        ->label('Select File')
                                        ->placeholder('-')
                                        ->options(static fn(): array => DataMasterDocument::query()
                                            ->orderBy('original_name')
                                            ->pluck('original_name', 'id')
                                            ->toArray())
                                        ->searchable()
                                        ->preload()
                                        ->live()
                                        ->visible(fn(Get $get): bool => in_array($get('input_mode'), ['document', 'document_manual_nik'], true))
                                        ->required(fn(Get $get): bool => in_array($get('input_mode'), ['document', 'document_manual_nik'], true)),
                                    Select::make('data_nik_input_id')
                                        ->label('Select NIK (sort by order)')
                                        ->placeholder('-')
                                        ->options(function (Get $get): array {
                                            $documentId = $get('data_master_document_id');

                                            if (!$documentId) {
                                                return [];
                                            }

                                            return DataNikInput::query()
                                                ->where('data_master_document_id', $documentId)
                                                ->orderBy('order')
                                                ->get()
                                                ->mapWithKeys(fn(DataNikInput $input) => [
                                                    $input->id => sprintf('#%d - %s', $input->order, $input->nik),
                                                ])
                                                ->toArray();
                                        })
                                        ->searchable()
                                        ->live()
                                        ->visible(fn(Get $get): bool => $get('input_mode') === 'document_manual_nik')
                                        ->required(fn(Get $get): bool => $get('input_mode') === 'document_manual_nik'),
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
                                    'nik' => $data['manual_nik'],
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

                            if (!in_array($inputMode, ['document', 'document_manual_nik'], true)) {
                                Notification::make()
                                    ->title('Metode input belum dipilih.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $documentId = $data['data_master_document_id'] ?? null;
                            $isManualNikSelection = $inputMode === 'document_manual_nik';

                            if (!$documentId) {
                                Notification::make()
                                    ->title('Silakan pilih dokumen terlebih dahulu.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $startingOrder = null;

                            if ($isManualNikSelection) {
                                $selectedNikId = $data['data_nik_input_id'] ?? null;

                                if (!$selectedNikId) {
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

                                if (!$selectedNik) {
                                    Notification::make()
                                        ->title('Data NIK tidak ditemukan pada dokumen terpilih.')
                                        ->danger()
                                        ->send();

                                    return;
                                }

                                $startingOrder = (int) $selectedNik->order;
                            } else {
                                $lastNikValue = $record->last_nik_input;

                                if (!$lastNikValue) {
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

                                if (!$lastNikRecord) {
                                    Notification::make()
                                        ->title('NIK terakhir tidak ditemukan pada dokumen ini.')
                                        ->body('Silakan pilih NIK secara manual.')
                                        ->danger()
                                        ->send();

                                    return;
                                }

                                $startingOrder = ((int) $lastNikRecord->order) + 1;
                            }

                            $nikInputs = DataNikInput::query()
                                ->where('data_master_document_id', $documentId)
                                ->where('order', '>=', $startingOrder)
                                ->orderBy('order')
                                ->get(['id', 'nik']);

                            if ($nikInputs->isEmpty()) {
                                Notification::make()
                                    ->title('Tidak ada NIK yang bisa diproses.')
                                    ->body($isManualNikSelection
                                        ? 'Silakan pilih NIK lainnya.'
                                        : 'Semua NIK pada dokumen ini telah diproses.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $accountId = (int) $record->id;
                            $accountEmail = $record->email;
                            $documentIdInt = (int) $documentId;
                            $userId = auth()->id();
                            $total = $nikInputs->count();

                            // ID unik untuk satu kali dispatch. Men-scope counter progres
                            // supaya job run lama yang masih nyangkut tidak menimpa run baru.
                            $runId = (string) Str::uuid();

                            $progressKey = "nik_progress:{$accountId}";
                            Cache::put($progressKey, [
                                'run_id'     => $runId,
                                'total'      => $total,
                                'done'       => 0,
                                'status'     => 'running',
                                'started_at' => now()->toIso8601String(),
                            ], now()->addHours(2));

                            // Rangkai 1 ProcessNikJob per NIK. Bus::chain menjamin job
                            // berikutnya hanya dijalankan setelah job sebelumnya selesai,
                            // sehingga urutan NIK terjaga ketat (1 -> N) berapa pun jumlah worker.
                            // `position` (1-based) dipakai untuk menghitung progres.
                            $jobs = [];
                            foreach ($nikInputs->values() as $i => $nikInput) {
                                $jobs[] = new ProcessNikJob(
                                    account_id: $accountId,
                                    data_master_document_id: $documentIdInt,
                                    data_nik_input_id: $nikInput->id,
                                    run_id: $runId,
                                    position: $i + 1,
                                    total: $total,
                                );
                            }

                            // Job penutup: kirim notif "selesai" saat seluruh rangkaian beres.
                            $jobs[] = new NotifyNikChainCompleted($accountId, $total, $userId, $runId);

                            // Arahkan ke koneksi 'redis' karena Horizon (worker production)
                            // hanya memproses queue redis, sedangkan default QUEUE_CONNECTION
                            // project ini masih 'database' yang tidak di-supervise Horizon.
                            Bus::chain($jobs)
                                ->onConnection('redis')
                                ->catch(function (\Throwable $e) use ($accountEmail, $userId, $progressKey, $runId): void {
                                    // Hentikan progress bar & tandai kondisi akhir chain.
                                    $state = Cache::get($progressKey);
                                    if (is_array($state) && ($state['run_id'] ?? null) === $runId) {
                                        $state['status'] = $e instanceof StockExhaustedException ? 'stopped' : 'failed';
                                        Cache::put($progressKey, $state, now()->addMinute());
                                    }

                                    $user = $userId ? User::find($userId) : null;
                                    if (! $user) {
                                        return;
                                    }

                                    if ($e instanceof StockExhaustedException) {
                                        Notification::make()
                                            ->title('Stok hari ini sudah ter-input semua.')
                                            ->body("Proses dihentikan untuk akun {$accountEmail} karena stok habis.")
                                            ->success()
                                            ->sendToDatabase($user);

                                        return;
                                    }

                                    Notification::make()
                                        ->title('Proses input NIK terhenti')
                                        ->body("Terjadi kendala pada akun {$accountEmail}: {$e->getMessage()}")
                                        ->danger()
                                        ->sendToDatabase($user);
                                })
                                ->dispatch();

                            Notification::make()
                                ->title("{$nikInputs->count()} NIK masuk antrian")
                                ->body('Proses berjalan di background, hasil akan diberitahukan saat selesai.')
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
            ])
            ->poll('5s');
    }

    protected static function fetchSalesReportData(Account $record, Carbon $start, Carbon $end, ?string $search = null): array
    {
        if (!Cache::get("merchant_api_token_{$record->email}") || Cache::get("merchant_api_token_{$record->email}") == NULL) {
            Artisan::call('merchant:fetch-token', [
                '--email' => $record->email,
                '--pin' => $record->pin,
            ]);
        }

        $token = Cache::get("merchant_api_token_{$record->email}");

        if (!$token) {
            return ['summary' => [], 'customers' => []];
        }

        $queryParams = [
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
            'sort' => 'latest',
        ];

        // Add search parameter if provided
        if ($search !== null && trim($search) !== '') {
            $queryParams['search'] = trim($search);
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->get('https://api-map.my-pertamina.id/general/v3/transactions/report', $queryParams);

        if ($response->status() === 401) {
            Cache::forget("merchant_api_token_{$record->email}");
            return ['summary' => [], 'customers' => []];
        }

        if ($response->failed()) {
            return ['summary' => [], 'customers' => []];
        }

        $payload = $response->json();

        if (($payload['code'] ?? null) !== 200 || ($payload['status'] ?? null) !== 'OK') {
            return ['summary' => [], 'customers' => []];
        }

        $dataPayload = $payload['data'] ?? [];
        $summaryReport = data_get($dataPayload, 'summaryReport', []);

        $customers = collect(data_get($dataPayload, 'customersReport', []))
            ->map(fn($customer) => [
                'customer_report_id' => data_get($customer, 'customerReportId'),
                'nationality_id' => data_get($customer, 'nationalityId'),
                'name' => data_get($customer, 'name'),
                'categories' => collect(data_get($customer, 'categories', []))
                    ->map(fn($category) => data_get($category, 'name') ?? (is_string($category) ? $category : null))
                    ->filter()
                    ->values()
                    ->all(),
                'total' => data_get($customer, 'total'),
            ])
            ->values()
            ->all();

        return [
            'summary' => [
                'sold' => data_get($summaryReport, 'sold'),
                'gross' => data_get($summaryReport, 'gross'),
                'modal' => data_get($summaryReport, 'modal'),
                'profit' => data_get($summaryReport, 'profit'),
            ],
            'customers' => $customers,
        ];
    }

    protected static function loadSalesReport(Action $action, Account $record, Carbon $start, Carbon $end, bool $notifySuccess = false): bool
    {
        if (!Cache::get("merchant_api_token_{$record->email}") || Cache::get("merchant_api_token_{$record->email}") == NULL) {
            Artisan::call('merchant:fetch-token', [
                '--email' => $record->email,
                '--pin' => $record->pin,
            ]);
        }

        $token = Cache::get("merchant_api_token_{$record->email}");

        if (!$token) {
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

        $customers = collect(data_get($dataPayload, 'customersReport', []))
            ->map(fn($customer) => [
                'customer_report_id' => data_get($customer, 'customerReportId'),
                'nationality_id' => data_get($customer, 'nationalityId'),
                'name' => data_get($customer, 'name'),
                'categories' => collect(data_get($customer, 'categories', []))
                    ->map(fn($category) => data_get($category, 'name') ?? (is_string($category) ? $category : null))
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
            'summary_sold' => data_get($summaryReport, 'sold'),
            'summary_gross' => data_get($summaryReport, 'gross'),
            'summary_modal' => data_get($summaryReport, 'modal'),
            'summary_profit' => data_get($summaryReport, 'profit'),
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

    protected static function loadSalesReportWithSet(Action $action, Account $record, Carbon $start, Carbon $end, \Filament\Schemas\Components\Utilities\Set $set): bool
    {
        if (!Cache::get("merchant_api_token_{$record->email}") || Cache::get("merchant_api_token_{$record->email}") == NULL) {
            Artisan::call('merchant:fetch-token', [
                '--email' => $record->email,
                '--pin' => $record->pin,
            ]);
        }

        $token = Cache::get("merchant_api_token_{$record->email}");

        if (!$token) {
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
                ->body('Terjadi kesalahan saat menghubungi server.')
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

        $customers = collect(data_get($dataPayload, 'customersReport', []))
            ->map(fn($customer) => [
                'customer_report_id' => data_get($customer, 'customerReportId'),
                'nationality_id' => data_get($customer, 'nationalityId'),
                'name' => data_get($customer, 'name'),
                'categories' => collect(data_get($customer, 'categories', []))
                    ->map(fn($category) => data_get($category, 'name') ?? (is_string($category) ? $category : null))
                    ->filter()
                    ->values()
                    ->all(),
                'total' => data_get($customer, 'total'),
            ])
            ->values()
            ->all();

        // Use Set for reactive updates
        $set('summary_sold', data_get($summaryReport, 'sold'));
        $set('summary_gross', data_get($summaryReport, 'gross'));
        $set('summary_modal', data_get($summaryReport, 'modal'));
        $set('summary_profit', data_get($summaryReport, 'profit'));
        $set('report_customers', $customers);

        Notification::make()
            ->title('Rekap penjualan berhasil dimuat.')
            ->success()
            ->send();

        return true;
    }

    protected static function loadSalesReportDirect(Action $action, Account $record, Carbon $start, Carbon $end, $livewire): bool
    {
        if (!Cache::get("merchant_api_token_{$record->email}") || Cache::get("merchant_api_token_{$record->email}") == NULL) {
            Artisan::call('merchant:fetch-token', [
                '--email' => $record->email,
                '--pin' => $record->pin,
            ]);
        }

        $token = Cache::get("merchant_api_token_{$record->email}");

        if (!$token) {
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
                ->body('Terjadi kesalahan saat menghubungi server.')
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

        $customers = collect(data_get($dataPayload, 'customersReport', []))
            ->map(fn($customer) => [
                'customer_report_id' => data_get($customer, 'customerReportId'),
                'nationality_id' => data_get($customer, 'nationalityId'),
                'name' => data_get($customer, 'name'),
                'categories' => collect(data_get($customer, 'categories', []))
                    ->map(fn($category) => data_get($category, 'name') ?? (is_string($category) ? $category : null))
                    ->filter()
                    ->values()
                    ->all(),
                'total' => data_get($customer, 'total'),
            ])
            ->values()
            ->all();

        // Update Livewire component's mounted actions data directly
        $mountedIndex = array_key_last($livewire->mountedActions);

        if ($mountedIndex !== null) {
            $basePath = "mountedActions.{$mountedIndex}.data";

            $livewire->set("{$basePath}.summary_sold", data_get($summaryReport, 'sold'));
            $livewire->set("{$basePath}.summary_gross", data_get($summaryReport, 'gross'));
            $livewire->set("{$basePath}.summary_modal", data_get($summaryReport, 'modal'));
            $livewire->set("{$basePath}.summary_profit", data_get($summaryReport, 'profit'));
            $livewire->set("{$basePath}.report_customers", $customers);
        }

        Notification::make()
            ->title('Rekap penjualan berhasil dimuat.')
            ->success()
            ->send();

        return true;
    }

    protected static function formatNumber(mixed $value): string
    {
        return is_numeric($value)
            ? number_format((float) $value, 0, ',', '.')
            : '-';
    }

    protected static function formatCurrency(mixed $value): string
    {
        return is_numeric($value)
            ? 'Rp ' . number_format((float) $value, 0, ',', '.')
            : '-';
    }

    protected static function renderStatCard(string $label, string $value, string $description, string $icon, string $color): string
    {
        $iconSvg = match ($icon) {
            'heroicon-o-cube' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 20px; height: 20px;"><path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" /></svg>',
            'heroicon-o-banknotes' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 20px; height: 20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>',
            'heroicon-o-credit-card' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 20px; height: 20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" /></svg>',
            'heroicon-o-currency-dollar' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 20px; height: 20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>',
            default => '',
        };

        $colors = match ($color) {
            'emerald' => ['bg' => '#ecfdf5', 'text' => '#059669', 'border' => '#d1fae5'],
            'blue' => ['bg' => '#eff6ff', 'text' => '#2563eb', 'border' => '#dbeafe'],
            'amber' => ['bg' => '#fffbeb', 'text' => '#d97706', 'border' => '#fef3c7'],
            'purple' => ['bg' => '#faf5ff', 'text' => '#9333ea', 'border' => '#f3e8ff'],
            default => ['bg' => '#f9fafb', 'text' => '#6b7280', 'border' => '#f3f4f6'],
        };

        return <<<HTML
<div style="position: relative; overflow: hidden; border-radius: 12px; border: 1px solid {$colors['border']}; background-color: #ffffff; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
<div style="display: flex; align-items: flex-start; justify-content: space-between;">
<div>
<p style="font-size: 12px; font-weight: 500; color: #6b7280; margin: 0;">{$label}</p>
<p style="margin-top: 8px; font-size: 18px; font-weight: 700; color: #111827; margin-bottom: 0; white-space: nowrap;">{$value}</p>
</div>
<div style="border-radius: 8px; padding: 8px; background-color: {$colors['bg']}; color: {$colors['text']}; display: flex; align-items: center; justify-content: center;">
{$iconSvg}
</div>
</div>
<div style="margin-top: 12px;">
<span style="font-size: 10px; font-weight: 500; color: #9ca3af;">{$description}</span>
</div>
</div>
HTML;
    }

    /**
     * Render response API Pertamina (kolom api_response) sebagai blok collapsible.
     * JSON dirapikan bila valid; kalau kosong (entri lama sebelum fitur ini)
     * tampilkan catatan bahwa response tidak tersimpan.
     */
    protected static function renderApiResponseBlock(?string $apiResponse): string
    {
        if (blank($apiResponse)) {
            return '<div style="font-size:11px;color:#9ca3af;font-style:italic;margin-top:4px;">Response API tidak tersimpan.</div>';
        }

        $decoded = json_decode($apiResponse, true);
        $pretty = json_last_error() === JSON_ERROR_NONE
            ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $apiResponse;

        $escaped = e($pretty);

        return <<<HTML
<details style="margin-top:6px;">
<summary style="cursor:pointer;font-size:11px;color:#2563eb;user-select:none;">Response API Pertamina</summary>
<pre style="margin-top:4px;padding:8px;background-color:#0f172a;color:#e2e8f0;border-radius:6px;font-size:11px;line-height:1.5;overflow-x:auto;white-space:pre-wrap;word-break:break-word;">{$escaped}</pre>
</details>
HTML;
    }

    /**
     * Render 10 inputan terakhir SATU akun (semua status: sukses/gagal/ditolak)
     * sebagai HTML untuk slideOver. Tidak ada kolom `status` di tabel — status
     * diturunkan dari is_failed + rejected_status
     * (sukses = is_failed=false DAN rejected_status kosong).
     */
    protected static function renderLastInputLog(Account $record): string
    {
        $histories = $record->nikInputHistories()
            ->with('document')
            ->latest('created_at')
            ->limit(10)
            ->get();

        if ($histories->isEmpty()) {
            return '<p style="font-size:14px;color:#6b7280;font-style:italic;">Belum ada input untuk akun ini.</p>';
        }

        $lastAt = $histories->first()->created_at?->format('d/m H:i') ?? '-';

        $rows = $histories->map(function ($history): string {
            $time = $history->created_at?->format('d/m H:i') ?? '-';
            $nik = e($history->nik ?? '-');
            $document = e($history->document?->original_name ?? '-');

            // rejected_status diperiksa lebih dulu: baris ditolak sekarang juga
            // ber-is_failed=true, jadi urutan terbalik akan menyembunyikan
            // alasan penolakannya di balik badge "Gagal" generik.
            if (filled($history->rejected_status)) {
                $badge = '<span style="background-color:#fef3c7;color:#b45309;border-radius:4px;padding:2px 6px;font-size:10px;font-weight:600;text-transform:uppercase;white-space:nowrap;">Ditolak: ' . e($history->rejected_status) . '</span>';
            } elseif ($history->is_failed) {
                $badge = '<span style="background-color:#fee2e2;color:#b91c1c;border-radius:4px;padding:2px 6px;font-size:10px;font-weight:600;text-transform:uppercase;white-space:nowrap;">Gagal</span>';
            } else {
                $badge = '<span style="background-color:#dcfce7;color:#15803d;border-radius:4px;padding:2px 6px;font-size:10px;font-weight:600;text-transform:uppercase;white-space:nowrap;">Sukses</span>';
            }

            $responseHtml = self::renderApiResponseBlock($history->api_response);

            return <<<HTML
<div style="padding:8px 0;border-bottom:1px solid #e5e7eb;">
<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
<div style="min-width:0;">
<div style="font-size:13px;font-family:ui-monospace,monospace;color:#111827;">{$nik}</div>
<div style="font-size:11px;color:#6b7280;">{$time} WIB &middot; {$document}</div>
</div>
<div style="flex-shrink:0;">{$badge}</div>
</div>
{$responseHtml}
</div>
HTML;
        })->implode('');

        return <<<HTML
<div style="max-height:70vh;overflow-y:auto;">
<div style="font-size:12px;color:#6b7280;margin-bottom:8px;">Terakhir input: <span style="font-family:ui-monospace,monospace;font-weight:600;">{$lastAt} WIB</span></div>
{$rows}
</div>
HTML;
    }

    protected static function renderCategories(array $categories): string
    {
        if (empty($categories)) {
            return '<span class="text-xs italic text-gray-400 dark:text-gray-500">-</span>';
        }

        $badges = collect($categories)
            ->map(fn(string $category): string => sprintf(
                '<span class="inline-flex items-center rounded-md bg-gray-50 dark:bg-gray-800 px-2 py-1 text-xs font-medium text-gray-600 dark:text-gray-300 ring-1 ring-inset ring-gray-500/10 dark:ring-gray-700">%s</span>',
                e($category)
            ))
            ->implode(' ');

        return '<div class="flex flex-wrap gap-1">' . $badges . '</div>';
    }

    /**
     * Check if total tabung filter is active
     */
    protected static function hasActiveFilter(Get $get): bool
    {
        $filterTotal = $get('filter_total_tabung');

        return $filterTotal !== null && $filterTotal !== '';
    }

    /**
     * Get display value for sold - use API value when no filter, calculate when filtered
     */
    protected static function getDisplaySold(Get $get): int
    {
        $filterTotal = $get('filter_total_tabung');

        // Jika ada filter jumlah tabung, hitung dari customers yang difilter
        if (self::hasActiveFilter($get)) {
            return self::calculateFilteredSold($get('report_customers') ?? [], $filterTotal);
        }

        // Jika tidak ada filter, gunakan nilai dari API
        return (int) ($get('summary_sold') ?? 0);
    }

    /**
     * Get display value for gross - use API value when no filter, calculate when filtered
     */
    protected static function getDisplayGross(Get $get): int
    {
        $filterTotal = $get('filter_total_tabung');

        if (self::hasActiveFilter($get)) {
            return self::calculateFilteredGross($get('report_customers') ?? [], $filterTotal);
        }

        return (int) ($get('summary_gross') ?? 0);
    }

    /**
     * Get display value for modal - use API value when no filter, calculate when filtered
     */
    protected static function getDisplayModal(Get $get): int
    {
        $filterTotal = $get('filter_total_tabung');

        if (self::hasActiveFilter($get)) {
            return self::calculateFilteredModal($get('report_customers') ?? [], $filterTotal);
        }

        return (int) ($get('summary_modal') ?? 0);
    }

    /**
     * Get display value for profit - use API value when no filter, calculate when filtered
     */
    protected static function getDisplayProfit(Get $get): int
    {
        $filterTotal = $get('filter_total_tabung');

        if (self::hasActiveFilter($get)) {
            return self::calculateFilteredProfit($get('report_customers') ?? [], $filterTotal);
        }

        return (int) ($get('summary_profit') ?? 0);
    }

    protected static function filterCustomersByTotal(array $customers, ?string $filterTotal): array
    {
        if ($filterTotal === null || $filterTotal === '') {
            return $customers;
        }

        return collect($customers)
            ->filter(fn(array $customer): bool => (int) ($customer['total'] ?? 0) === (int) $filterTotal)
            ->values()
            ->all();
    }

    protected static function calculateFilteredSold(array $customers, ?string $filterTotal): int
    {
        $filtered = self::filterCustomersByTotal($customers, $filterTotal);

        return collect($filtered)->sum(fn(array $customer): int => (int) ($customer['total'] ?? 0));
    }

    protected static function calculateFilteredGross(array $customers, ?string $filterTotal): int
    {
        $filtered = self::filterCustomersByTotal($customers, $filterTotal);
        $count = count($filtered);

        // Harga per tabung Rp 18.500
        return $count > 0 ? self::calculateFilteredSold($customers, $filterTotal) * 18500 : 0;
    }

    protected static function calculateFilteredModal(array $customers, ?string $filterTotal): int
    {
        $filtered = self::filterCustomersByTotal($customers, $filterTotal);
        $count = count($filtered);

        // Modal per tabung Rp 17.600
        return $count > 0 ? self::calculateFilteredSold($customers, $filterTotal) * 17600 : 0;
    }

    protected static function calculateFilteredProfit(array $customers, ?string $filterTotal): int
    {
        return self::calculateFilteredGross($customers, $filterTotal) - self::calculateFilteredModal($customers, $filterTotal);
    }

    protected static function renderCustomersList(array $customers): string
    {
        if (empty($customers)) {
            return '<p style="font-size: 14px; color: #6b7280; font-style: italic;">Tidak ada data konsumen.</p>';
        }

        $rows = collect($customers)->map(function (array $customer, int $index): string {
            $fullReportId = $customer['customer_report_id'] ?? '-';
            $reportId = strlen($fullReportId) > 8 ? '...' . substr($fullReportId, -6) : $fullReportId;
            $name = e($customer['name'] ?? 'Tanpa Nama');
            $nik = e($customer['nationality_id'] ?? '-');
            $total = (int) ($customer['total'] ?? 0);
            $categories = self::renderCategoriesInline($customer['categories'] ?? []);
            $bgColor = $index % 2 === 0 ? '#f9fafb' : '#ffffff';

            return <<<HTML
<tr style="background-color: {$bgColor};" title="ID: {$fullReportId}">
<td style="padding: 8px 12px; font-size: 13px; color: #6b7280;">{$reportId}</td>
<td style="padding: 8px 12px; font-size: 13px; color: #111827;">{$name}</td>
<td style="padding: 8px 12px; font-size: 13px; color: #6b7280;">{$nik}</td>
<td style="padding: 8px 12px; font-size: 13px; font-weight: 600; color: #f59e0b; white-space: nowrap;">{$total}&nbsp;Tabung</td>
<td style="padding: 8px 12px; font-size: 13px; color: #6b7280;">{$categories}</td>
</tr>
HTML;
        })->implode('');

        return <<<HTML
<div style="overflow-x: auto; max-height: 400px; overflow-y: auto;">
<table style="width: 100%; border-collapse: collapse; min-width: 600px;">
<thead>
<tr style="background-color: #f3f4f6; border-bottom: 2px solid #e5e7eb;">
<th style="padding: 10px 12px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase;">ID</th>
<th style="padding: 10px 12px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase;">Nama</th>
<th style="padding: 10px 12px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase;">NIK</th>
<th style="padding: 10px 12px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase;">Total</th>
<th style="padding: 10px 12px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase;">Kategori</th>
</tr>
</thead>
<tbody>
{$rows}
</tbody>
</table>
</div>
HTML;
    }

    protected static function renderCategoriesInline(array $categories): string
    {
        if (empty($categories)) {
            return '-';
        }

        return collect($categories)
            ->map(fn(string $category): string => e($category))
            ->implode(', ');
    }
}
