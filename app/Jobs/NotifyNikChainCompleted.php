<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Job penutup rangkaian (chain) input NIK.
 *
 * Diletakkan sebagai elemen TERAKHIR pada Bus::chain. Saat job ini dijalankan,
 * artinya semua ProcessNikJob sebelumnya sudah selesai (chain tidak terhenti oleh
 * stok habis / error tak terduga), sehingga aman mengirim notifikasi "selesai"
 * ke admin yang memicu proses.
 */
class NotifyNikChainCompleted implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $account_id,
        public int $total,
        public ?int $user_id,
        public string $run_id = '',
    ) {}

    public function handle(): void
    {
        // Finalisasi progres: bar tampil penuh sebentar lalu hilang sendiri
        // (TTL pendek). Diabaikan bila run_id tidak cocok (sudah ditimpa run baru).
        if ($this->run_id !== '') {
            $key = "nik_progress:{$this->account_id}";
            $state = Cache::get($key);

            if (is_array($state) && ($state['run_id'] ?? null) === $this->run_id) {
                $state['done'] = $this->total;
                $state['status'] = 'completed';
                Cache::put($key, $state, now()->addMinute());
            }
        }

        $user = $this->user_id ? User::find($this->user_id) : null;
        if (! $user) {
            return;
        }

        $account = Account::find($this->account_id);
        $email = $account?->email ?? "#{$this->account_id}";

        Notification::make()
            ->title('Input NIK selesai')
            ->body("Selesai memproses {$this->total} NIK untuk akun {$email}.")
            ->success()
            ->sendToDatabase($user);
    }
}
