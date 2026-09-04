<?php

namespace App\Models\Workshop;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Sale extends Model
{
    protected $table = 'workshop_sales';

    protected $fillable = [
        'sale_number',
        'cashier_id',
        'customer_name',
        'payment_method',
        'total',
        'paid',
        'change',
        'status',
        'payment_status',
        'qris_transaction_id',
        'qris_issuer',
        'qris_qr_url',
        'qris_checkout_url',
        'qris_expires_at',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'paid' => 'decimal:2',
        'change' => 'decimal:2',
        'qris_expires_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'stock_released_at' => 'datetime',
    ];

    /** Hanya transaksi berstatus ini yang boleh dihitung sebagai omzet. */
    public const STATUS_COUNTED = 'paid';

    protected static function booted(): void
    {
        static::creating(function (Sale $sale): void {
            if (empty($sale->sale_number)) {
                $sale->sale_number = self::generateSaleNumber();
            }

            if (empty($sale->cashier_id) && auth()->check()) {
                $sale->cashier_id = auth()->id();
            }
        });

        static::retrieved(function (Sale $sale): void {
            // Auto-check dan update status expired saat record di-retrieve
            $sale->checkAndUpdateQrisExpired();
        });
    }

    public static function generateSaleNumber(): string
    {
        return 'WS-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6));
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'cashier_id');
    }

    public function recalculateTotals(): void
    {
        $total = $this->items()->sum('subtotal');
        $paid = $this->paid ?? $total;
        $change = max($paid - $total, 0);

        $this->forceFill([
            'total' => $total,
            'change' => $change,
        ])->save();
    }

    public function isQrisExpired(): bool
    {
        return $this->payment_method === 'qris'
            && $this->payment_status === 'pending'
            && $this->qris_expires_at
            && now()->isAfter($this->qris_expires_at);
    }

    public function checkAndUpdateQrisExpired(): void
    {
        if (! $this->isQrisExpired()) {
            return;
        }

        // Status dulu, baru stok. Selama payment_status masih 'pending',
        // setiap pembacaan model memicu ulang metode ini lewat hook retrieved.
        $this->update([
            'payment_status' => 'expired',
            'status' => 'expired',
        ]);

        $this->releaseStock('QRIS kedaluwarsa — ' . $this->sale_number);
    }

    /**
     * Batalkan transaksi: stok kembali, dan barisnya berhenti dihitung omzet.
     */
    public function cancel(string $reason, ?int $userId = null): void
    {
        DB::transaction(function () use ($reason, $userId): void {
            $this->releaseStock('Pembatalan ' . $this->sale_number . ' — ' . $reason);

            $this->forceFill([
                'status' => 'cancelled',
                'payment_status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $userId ?? auth()->id(),
                'cancel_reason' => $reason,
            ])->save();
        });
    }

    /**
     * Kembalikan stok yang ditahan transaksi ini.
     *
     * Stok dipotong sejak item dibuat — itu memang disengaja, barangnya sudah
     * disisihkan untuk customer. Yang dulu hilang adalah jalan pulangnya: QRIS
     * yang tidak jadi dibayar tetap memotong stok selamanya. Metode ini
     * mencatat mutasi 'in' penyeimbang, sekali saja.
     *
     * @return bool true kalau pengembalian benar-benar terjadi di panggilan ini.
     */
    public function releaseStock(string $note): bool
    {
        return $this->shiftStock('in', $note, released: true);
    }

    /**
     * Kebalikan releaseStock: tarik lagi stoknya.
     *
     * Dipakai saat transaksi yang sudah terlanjur ditandai expired ternyata
     * dibayar — vonis AutoGoPay datang belakangan lewat webhook atau
     * rekonsiliasi, dan barangnya tetap berpindah ke customer.
     */
    public function reclaimStock(string $note): bool
    {
        return $this->shiftStock('out', $note, released: false);
    }

    /**
     * Mesin bersama releaseStock/reclaimStock.
     *
     * Baris dikunci dan `stock_released_at` dibaca lewat query builder, bukan
     * Eloquent, supaya hook retrieved tidak ikut jalan dan memanggil balik
     * checkAndUpdateQrisExpired dari dalam sini.
     */
    protected function shiftStock(string $type, string $note, bool $released): bool
    {
        return DB::transaction(function () use ($type, $note, $released): bool {
            $current = DB::table($this->getTable())
                ->where('id', $this->getKey())
                ->lockForUpdate()
                ->value('stock_released_at');

            // Sudah dalam keadaan yang dituju: tidak ada yang perlu dikerjakan.
            if ($released === ($current !== null)) {
                return false;
            }

            foreach ($this->items()->get() as $item) {
                StockMovement::create([
                    'product_id' => $item->product_id,
                    'type' => $type,
                    'quantity' => $item->quantity,
                    'unit_cost' => $item->unit_price,
                    'reference_type' => static::class,
                    'reference_id' => $this->getKey(),
                    'note' => $note,
                ]);
            }

            $stamp = $released ? now() : null;

            DB::table($this->getTable())
                ->where('id', $this->getKey())
                ->update(['stock_released_at' => $stamp, 'updated_at' => now()]);

            $this->setAttribute('stock_released_at', $stamp)
                ->syncOriginalAttribute('stock_released_at');

            return true;
        });
    }

    /** Transaksi yang sah dihitung sebagai penjualan. */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COUNTED);
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'cancelled_by');
    }
}
