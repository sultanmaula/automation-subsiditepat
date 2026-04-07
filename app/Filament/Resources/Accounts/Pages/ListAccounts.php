<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use App\Models\DataNikInput;
use App\Models\NikInputHistory;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\NikInputController;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Action::make('sendReminder')
            //     ->label('Kirim Reminder Manual')
            //     ->icon('heroicon-o-bell-alert')
            //     ->color('warning')
            //     ->requiresConfirmation()
            //     ->modalHeading('Kirim Reminder Stok')
            //     ->modalDescription('Kirim notifikasi reminder stok ke semua akun?')
            //     ->action(function () {
            //         $accounts = Account::all();
            //         $count = 0;

            //         foreach ($accounts as $account) {
            //             Artisan::call('account:auto-send-reminder', [
            //                 'account' => $account->id,
            //             ]);
            //             $count++;
            //         }

            //         Notification::make()
            //             ->title('Reminder terkirim')
            //             ->body("Berhasil mengirim reminder ke {$count} akun.")
            //             ->success()
            //             ->send();
            //     }),
            Action::make('todayRecap')
                ->label('Input Recap')
                ->icon('heroicon-o-clipboard-document-list')
                ->color('info')
                ->modalHeading('Input Recap')
                ->modalWidth('2xl')
                ->modalSubmitAction(false)
                ->modalCancelAction(false)
                ->form([
                    Section::make('Filter Tanggal')
                        ->schema([
                            DatePicker::make('recap_date')
                                ->label('Select Date')
                                ->default(today())
                                ->native(false)
                                ->displayFormat('d/m/Y')
                                ->maxDate(today())
                                ->live(),
                        ])
                        ->columns(1),
                    Placeholder::make('recap_content')
                        ->hiddenLabel()
                        ->live()
                        ->content(fn (Get $get): HtmlString => $this->generateRecapHtml(
                            $get('recap_date') ? Carbon::parse($get('recap_date')) : today()
                        )),
                    Actions::make([
                        Action::make('sendToWhatsApp')
                            ->label('Send to WhatsApp')
                            ->icon('heroicon-o-chat-bubble-left-ellipsis')
                            ->color('success')
                            ->action(function (Get $get): void {
                                $date = $get('recap_date') ? Carbon::parse($get('recap_date')) : today();
                                $url = $this->generateWhatsAppUrl($date);

                                $this->js("window.open('{$url}', '_blank')");
                            }),
                    ])->fullWidth(),
                ]),
            // Action::make('sendDailyRecap')
            //     ->label('Kirim Rekap Harian')
            //     ->icon('heroicon-o-paper-airplane')
            //     ->color('success')
            //     ->requiresConfirmation()
            //     ->modalHeading('Kirim Rekap Harian')
            //     ->modalDescription('Kirim rekapan harian total transaksi semua akun via WhatsApp?')
            //     ->action(function () {
            //         Artisan::call('account:daily-recap');

            //         Notification::make()
            //             ->title('Rekap harian terkirim')
            //             ->body(trim(Artisan::output()))
            //             ->success()
            //             ->send();
            //     }),
            Action::make('deleteNikInput')
                ->label('Delete Data')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Delete Data Last Month')
                ->modalDescription('Delete all data last month from the system?')
                ->action(function () {
                    $result = (new NikInputController)->deleteLastMonth();

                    Notification::make()
                        ->title('Data berhasil dihapus')
                        ->body($result['message'])
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }

    protected function getRecapData(?Carbon $date = null): Collection
    {
        $targetDate = $date ?? Carbon::today();

        return NikInputHistory::with(['account', 'document', 'dataNikInput'])
            ->where('input_date', $targetDate->toDateString())
            ->orderBy('account_id')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    protected function generateRecapHtml(?Carbon $date = null): HtmlString
    {
        $targetDate = $date ?? Carbon::today();
        $histories = $this->getRecapData($targetDate);

        if ($histories->isEmpty()) {
            $dateFormatted = $targetDate->format('d-m-Y');
            return new HtmlString('<p class="text-gray-500 italic">No input on ' . $dateFormatted . '.</p>');
        }

        $grouped = $histories->groupBy('account_id');
        $output = '';

        foreach ($grouped as $accountHistories) {
            $account = $accountHistories->first()->account;
            $accountName = $account?->email ?? 'Unknown';
            $totalInput = $accountHistories->where('is_failed', false)->count();
            $lastInputTime = $accountHistories->first()->created_at?->format('H:i') ?? '-';

            $output .= '<div class="mb-6 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">';
            $output .= '<div class="font-bold text-lg text-primary-600 dark:text-primary-400"><b>INPUT ' . e($accountName) . '</b></div>';
            $output .= '<div class="text-sm text-gray-600 dark:text-gray-400">' . $targetDate->format('d-m-Y') . '</div>';
            $output .= '<div class="font-semibold mt-2">JUMLAH : ' . $totalInput . '</div>';
            $output .= '<div class="text-sm text-gray-500 dark:text-gray-400">Terakhir input: <span class="font-mono font-semibold">' . $lastInputTime . ' WIB</span></div>';
            $output .= '<div class="mt-3 space-y-1">';

            $byDocument = $accountHistories->groupBy('data_master_document_id');

            foreach ($byDocument as $docHistories) {
                $lastHistory = $docHistories->first();
                $documentName = $lastHistory->document?->original_name ?? 'Unknown Document';
                $lastNik = $lastHistory->nik ?? '-';

                $documentId = $lastHistory->data_master_document_id;
                $totalNiksInDocument = DataNikInput::where('data_master_document_id', $documentId)->count();
                $totalInputsForDocument = NikInputHistory::where('account_id', $lastHistory->account_id)
                    ->where('data_master_document_id', $documentId)
                    ->where('input_month', $targetDate->format('Y-m'))
                    ->count();

                $rotation = $totalNiksInDocument > 0
                    ? (int) ceil($totalInputsForDocument / $totalNiksInDocument)
                    : 1;

                $output .= '<div class="text-sm">- ' . e($documentName) . ' <span class="font-mono bg-gray-200 dark:bg-gray-700 px-1 rounded">(' . $rotation . ')</span> - <span class="font-mono">' . e($lastNik) . '</span></div>';
            }

            $output .= '</div>';
            $output .= '</div><br>';
        }

        return new HtmlString($output);
    }

    protected function generateRecapText(?Carbon $date = null): string
    {
        $targetDate = $date ?? Carbon::today();
        $histories = $this->getRecapData($targetDate);

        if ($histories->isEmpty()) {
            return 'Belum ada input pada tanggal ' . $targetDate->format('d-m-Y') . '.';
        }

        $grouped = $histories->groupBy('account_id');
        $lines = [];

        foreach ($grouped as $accountHistories) {
            $account = $accountHistories->first()->account;
            $accountName = $account?->email ?? 'Unknown';
            $totalInput = $accountHistories->count();
            $lastInputTime = $accountHistories->first()->created_at?->format('H:i') ?? '-';

            $lines[] = '*INPUT ' . $accountName . '*';
            $lines[] = $targetDate->format('d-m-Y');
            $lines[] = 'JUMLAH : ' . $totalInput;
            $lines[] = 'Terakhir input: ' . $lastInputTime . ' WIB';

            $byDocument = $accountHistories->groupBy('data_master_document_id');

            foreach ($byDocument as $docHistories) {
                $lastHistory = $docHistories->first();
                $documentName = $lastHistory->document?->original_name ?? 'Unknown Document';
                $lastNik = $lastHistory->nik ?? '-';

                $documentId = $lastHistory->data_master_document_id;
                $totalNiksInDocument = DataNikInput::where('data_master_document_id', $documentId)->count();
                $totalInputsForDocument = NikInputHistory::where('account_id', $lastHistory->account_id)
                    ->where('data_master_document_id', $documentId)
                    ->count();

                $rotation = $totalNiksInDocument > 0
                    ? (int) ceil($totalInputsForDocument / $totalNiksInDocument)
                    : 1;

                $lines[] = '- ' . $documentName . ' (' . $rotation . ') - ' . $lastNik;
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    protected function generateWhatsAppUrl(?Carbon $date = null): string
    {
        $text = $this->generateRecapText($date);

        return 'https://wa.me/?text=' . urlencode($text);
    }
}
