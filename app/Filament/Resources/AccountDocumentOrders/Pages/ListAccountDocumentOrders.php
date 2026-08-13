<?php

namespace App\Filament\Resources\AccountDocumentOrders\Pages;

use App\Filament\Resources\AccountDocumentOrders\Actions\ManageAccountDocumentsAction;
use App\Filament\Resources\AccountDocumentOrders\AccountDocumentOrderResource;
use App\Models\AccountDocumentOrder;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;

class ListAccountDocumentOrders extends ListRecords
{
    protected static string $resource = AccountDocumentOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ManageAccountDocumentsAction::header(),
        ];
    }

    /**
     * Reorder per akun, bukan global.
     *
     * Bawaan Filament menomori ulang 1..N untuk semua baris di halaman, padahal
     * `order` di sini unik per akun. Jadi baris hasil drag dikelompokkan dulu
     * per akun, lalu nilai `order` yang SUDAH dimiliki kelompok itu ditukar
     * posisinya (permutasi). Dengan begitu penomoran tetap benar walau tabel
     * dikelompokkan atau dipaginasi, dan tidak pernah bentrok dengan baris akun
     * yang sama di halaman lain.
     *
     * Bentrok sesaat di tengah transaksi aman karena unique (account_id, order)
     * sudah DEFERRABLE INITIALLY DEFERRED.
     *
     * @param  array<int, int|string>  $order
     */
    public function reorderTable(array $order, int | string | null $draggedRecordKey = null): void
    {
        if (! $this->getTable()->isReorderable()) {
            return;
        }

        $records = AccountDocumentOrder::findMany($order)->keyBy('id');

        // Susun record mengikuti urutan hasil drag, lalu pisahkan per akun.
        // Baris yang diseret melintasi grup akun lain diabaikan: akunnya diambil
        // dari database, bukan dari posisi barunya di layar.
        $byAccount = collect($order)
            ->map(fn (int | string $key): ?AccountDocumentOrder => $records->get((int) $key))
            ->filter()
            ->groupBy('account_id');

        DB::transaction(function () use ($byAccount): void {
            foreach ($byAccount as $accountRecords) {
                $slots = $accountRecords->pluck('order')->sort()->values();

                foreach ($accountRecords->values() as $index => $record) {
                    $slot = (int) $slots[$index];

                    if ((int) $record->order !== $slot) {
                        $record->update(['order' => $slot]);
                    }
                }
            }
        });
    }
}
