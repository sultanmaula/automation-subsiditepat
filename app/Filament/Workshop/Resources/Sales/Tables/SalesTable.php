<?php

namespace App\Filament\Workshop\Resources\Sales\Tables;

use App\Models\Workshop\Sale;
use App\Services\AutoGoPayService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SalesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->columns([
                TextColumn::make('sale_number')
                    ->label('No. Transaksi')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer_name')
                    ->label('Pelanggan')
                    ->placeholder('-'),
                TextColumn::make('total')
                    ->label('Total')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('payment_method')
                    ->label('Metode Bayar')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'qris' => 'QRIS',
                        'cash' => 'Tunai',
                        default => $state,
                    })
                    ->tooltip(fn (Sale $record): ?string => $record->qris_issuer),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid'      => 'success',
                        'pending'   => 'warning',
                        'expired'   => 'danger',
                        'cancelled' => 'gray',
                        default     => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'paid'      => 'Lunas',
                        'pending'   => 'Menunggu',
                        'expired'   => 'Expired',
                        'cancelled' => 'Dibatalkan',
                        default     => $state,
                    })
                    ->tooltip(fn (Sale $record): ?string => $record->cancel_reason),
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('show_qris')
                    ->label('Tampilkan QRIS')
                    ->icon('heroicon-o-qr-code')
                    ->color('warning')
                    ->visible(fn (Sale $record): bool =>
                        $record->payment_method === 'qris' &&
                        $record->status === 'pending' &&
                        filled($record->qris_qr_url)
                    )
                    ->modalHeading(fn (Sale $record): string => 'QRIS — ' . $record->sale_number)
                    ->modalDescription(fn (Sale $record): string => 'Total: Rp ' . number_format((float) $record->total, 0, ',', '.') . ' — Scan QR di bawah untuk membayar.')
                    ->modalContent(fn (Sale $record) => view('filament.workshop.components.qris-modal', ['sale' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup'),

                Action::make('bayar_ulang')
                    ->label('Bayar Ulang')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->visible(fn (Sale $record): bool =>
                        $record->payment_method === 'qris' &&
                        $record->status === 'expired'
                    )
                    ->requiresConfirmation()
                    ->modalHeading('Generate QRIS Baru')
                    ->modalDescription(fn (Sale $record): string => 'Buat QRIS baru untuk transaksi ' . $record->sale_number . ' senilai Rp ' . number_format((float) $record->total, 0, ',', '.') . '?')
                    ->action(function (Sale $record): void {
                        $qrisData = app(AutoGoPayService::class)->generateQris((int) $record->total);

                        if (! $qrisData) {
                            Notification::make()
                                ->title('Gagal membuat QRIS baru')
                                ->body('Cek koneksi atau API key AutoGoPay.')
                                ->danger()
                                ->send();
                            return;
                        }

                        $record->update([
                            'payment_status'      => 'pending',
                            'status'              => 'pending',
                            'qris_transaction_id' => $qrisData['transaction_id'] ?? null,
                            'qris_qr_url'         => $qrisData['qr_url'] ?? null,
                            'qris_checkout_url'   => $qrisData['checkout_url'] ?? null,
                            'qris_expires_at'     => now()->addMinutes(15),
                        ]);

                        Notification::make()
                            ->title('QRIS baru berhasil dibuat')
                            ->body('Klik "Tampilkan QRIS" untuk menampilkan QR code.')
                            ->success()
                            ->send();
                    }),
                Action::make('batalkan')
                    ->label('Batalkan')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Sale $record): bool =>
                        $record->status !== 'cancelled' &&
                        auth()->user()?->hasPermission('void')
                    )
                    ->schema([
                        Textarea::make('reason')
                            ->label('Alasan pembatalan')
                            ->placeholder('Contoh: salah input barang, customer batal ambil.')
                            ->required()
                            ->maxLength(255)
                            ->rows(2),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading(fn (Sale $record): string => 'Batalkan ' . $record->sale_number)
                    ->modalDescription(fn (Sale $record): string => $record->status === 'paid'
                        // Transaksi lunas: uangnya sudah masuk, dan sistem ini
                        // tidak bisa menariknya kembali. Katakan terus terang.
                        ? 'Transaksi ini sudah lunas. Stok akan dikembalikan dan nilainya keluar dari omzet, tapi pengembalian uang ke customer harus dilakukan manual.'
                        : 'Stok akan dikembalikan dan transaksi ini tidak lagi dihitung sebagai penjualan.')
                    ->modalSubmitActionLabel('Ya, batalkan')
                    ->action(function (Sale $record, array $data): void {
                        $record->cancel($data['reason']);

                        Notification::make()
                            ->title('Transaksi dibatalkan')
                            ->body('Stok ' . $record->sale_number . ' sudah dikembalikan.')
                            ->success()
                            ->send();
                    }),

                Action::make('nota')
                    ->label('Cetak Nota')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->url(fn (Sale $record): string => route('workshop.nota', $record->id))
                    ->openUrlInNewTab(),
                ViewAction::make(),
            ]);
    }
}
