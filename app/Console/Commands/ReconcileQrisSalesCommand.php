<?php

namespace App\Console\Commands;

use App\Models\Workshop\Sale;
use App\Services\AutoGoPayService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileQrisSalesCommand extends Command
{
    protected $signature = 'workshop:reconcile-qris';

    protected $description = 'Cocokkan status transaksi QRIS ke AutoGoPay sebagai jaring pengaman kalau webhook tidak sampai';

    /**
     * Webhook tetap jalur utama; ini hanya jaring pengaman. Tanpa ini, satu
     * webhook yang tidak sampai membuat transaksi yang sudah dibayar customer
     * kedaluwarsa sendiri, dan tidak ada yang tahu selain customernya.
     */
    public function handle(AutoGoPayService $service): int
    {
        $sales = Sale::query()
            ->where('payment_method', 'qris')
            ->whereNotNull('qris_transaction_id')
            ->where(function ($query): void {
                $query
                    ->where('payment_status', 'pending')
                    // Yang sudah expired ikut ditanyakan sebentar, karena
                    // pembayaran mepet batas waktu bisa terkonfirmasi setelah
                    // timer lokal menyerah. Lewat itu, tidak realistis lagi —
                    // dan tanpa batas ini jumlah panggilan API menumpuk.
                    ->orWhere(fn ($q) => $q
                        ->where('payment_status', 'expired')
                        ->where('qris_expires_at', '>=', now()->subMinutes(30)));
            })
            ->get();

        if ($sales->isEmpty()) {
            return self::SUCCESS;
        }

        $settled = 0;
        $issuers = null;

        foreach ($sales as $sale) {
            $status = $service->checkStatus($sale->qris_transaction_id);

            // null = gagal menghubungi gateway. Diamkan; percobaan berikutnya
            // beberapa menit lagi. Jangan sekali-kali menyimpulkan "belum bayar".
            if ($status === null) {
                continue;
            }

            if (! in_array(strtoupper($status), AutoGoPayService::PAID_STATUSES, true)) {
                continue;
            }

            // Diambil sekali per run, dipakai bareng untuk semua sale yang ketemu lunas.
            $issuers ??= $service->fetchTransactions();

            $sale->forceFill([
                'payment_status' => 'settlement',
                'status' => 'paid',
                'qris_issuer' => $issuers[$sale->qris_transaction_id] ?? null,
            ])->save();

            // Kalau tadi sempat ditandai expired, stoknya sudah dikembalikan.
            $sale->reclaimStock('Pembayaran terkonfirmasi rekonsiliasi — ' . $sale->sale_number);

            $settled++;

            Log::channel('autogopay')->info('Rekonsiliasi menemukan transaksi lunas', [
                'sale_number' => $sale->sale_number,
                'transaction_id' => $sale->qris_transaction_id,
                'status' => $status,
            ]);
        }

        $this->info("Diperiksa: {$sales->count()}, diselamatkan: {$settled}");

        return self::SUCCESS;
    }
}
