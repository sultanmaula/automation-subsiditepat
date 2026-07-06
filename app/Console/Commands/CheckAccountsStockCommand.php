<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\MerchantTokenService;
use App\Services\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckAccountsStockCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-accounts-stock';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mengecek stok LPG untuk seluruh akun Pertamina dan mengirimkan notifikasi via WhatsApp jika stok tersedia';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $accounts = Account::all();

        if ($accounts->isEmpty()) {
            $this->warn('Tidak ada akun yang terdaftar di database.');
            return Command::SUCCESS;
        }

        $targetPhone = '6285231731037'; // Nomor WhatsApp Bos
        $nowWib = now()->timezone('Asia/Jakarta');

        $this->info("Memulai pengecekan stok LPG untuk " . $accounts->count() . " akun pada: " . $nowWib->format('Y-m-d H:i:s') . " WIB");

        $accountsWithStock = [];

        foreach ($accounts as $account) {
            $email = $account->email;
            $this->info("Memproses akun: {$email}");

            // Cek token cache, jika tidak ada, ambil token baru (dengan lock per-akun
            // agar tidak tumpang tindih dengan nik:auto-input untuk akun yang sama).
            $tokenCacheKey = "merchant_api_token_{$email}";
            $token = MerchantTokenService::getOrFetch($account);

            if (!$token) {
                $this->error("Gagal mendapatkan token untuk {$email}.");
                continue;
            }

            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                ])->timeout(15)->get('https://api-map.my-pertamina.id/general/products/v1/products/user');

                if ($response->successful()) {
                    $resData = $response->json();
                    
                    if (($resData['code'] ?? null) == 200 && ($resData['status'] ?? '') === 'OK') {
                        $data = $resData['data'] ?? [];
                        $storeName = $data['storeName'] ?? '-';
                        $stockAvailable = (int) ($data['stockAvailable'] ?? 0);
                        
                        $this->info("Akun: {$email} | Toko: {$storeName} | Stok: {$stockAvailable}");

                        // Jika stok tersedia (> 0), catat untuk notifikasi gabungan
                        if ($stockAvailable > 0) {
                            $accountsWithStock[] = [
                                'storeName' => $storeName,
                                'email'     => $email,
                                'stock'     => $stockAvailable,
                            ];
                        }
                    } else {
                        $this->error("Response API tidak sukses untuk {$email}: " . json_encode($resData));
                    }
                } else {
                    if ($response->status() === 401) {
                        $this->warn("Token kedaluwarsa (401) untuk {$email}. Menghapus token dari cache...");
                        Cache::forget($tokenCacheKey);
                    }
                    $this->error("Gagal request API Pertamina untuk {$email}. Status code: " . $response->status());
                }
            } catch (\Exception $e) {
                Log::error("Error saat mengecek stok untuk {$email}: " . $e->getMessage());
                $this->error("Error saat mengecek stok untuk {$email}: " . $e->getMessage());
            }
        }

        // Kirim 1 pesan gabungan jika ada akun yang stoknya tersedia
        if (!empty($accountsWithStock)) {
            $totalStock = array_sum(array_column($accountsWithStock, 'stock'));

            $message = "📦 *Stok LPG Tersedia*\n\n";

            foreach ($accountsWithStock as $i => $item) {
                $no = $i + 1;
                $message .= "{$no}. *{$item['storeName']}* — *{$item['stock']}* tabung\n"
                          . "    _{$item['email']}_\n";
            }

            $message .= "\nTotal: *{$totalStock}* tabung dari " . count($accountsWithStock) . " akun\n"
                      . "🕐 " . $nowWib->format('d M Y H:i') . " WIB";

            WhatsAppService::send($targetPhone, $message);
            $this->info("Notifikasi WhatsApp gabungan berhasil dikirim ke {$targetPhone} (" . count($accountsWithStock) . " akun).");
        }

        $this->info("Pengecekan stok selesai.");
        return Command::SUCCESS;
    }
}
