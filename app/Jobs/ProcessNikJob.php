<?php

namespace App\Jobs;

use App\Console\Commands\AutoInputNikCommand;
use App\Helpers\NikRegionHelper;
use App\Exceptions\AccountDailyLimitException;
use App\Exceptions\NikBlockedException;
use App\Exceptions\NikNotRegisteredException;
use App\Exceptions\NikQuotaExhaustedException;
use App\Exceptions\PertaminaRateLimitedException;
use App\Exceptions\PertaminaSystemErrorException;
use App\Exceptions\StockExhaustedException;
use App\Exceptions\TokenStaleException;
use App\Models\Account;
use App\Models\DataNikInput;
use App\Models\NikInputHistory;
use App\Services\MerchantTokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

class ProcessNikJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * =====================================================
     * QUEUE CONFIG
     * =====================================================
     */
    public int $tries = 20;                 // cukup untuk antre rate limit
    public array $backoff = [10, 30, 60];   // fallback kalau error beneran

    /**
     * Jarak minimum & maksimum (detik) antar tembakan API per akun. Diacak di
     * rentang ini setiap kali job selesai menembak API (lihat blok `finally`
     * di handle(), berlaku untuk SEMUA hasil — bukan hanya input sukses).
     *
     * 20 Jul 2026: diturunkan dari 30-90 ke 20-45 atas permintaan user.
     * Ini menaikkan throughput ~2x (200 input/akun jadi ~1,8 jam dari ~3,5 jam)
     * dan ikut menaikkan paparan ke anti-abuse Pertamina. Ambang aman TIDAK
     * diketahui: satu-satunya titik data adalah gap ~1 detik, yang berujung
     * blok level-akun permanen pada akun 1 (16 Jul) dan akun 4 (18 Jul).
     * Sinyal yang harus dipantau, urut dari paling dini:
     *   - TOO_MANY_REQUEST naik dibanding baseline (8 kejadian pada 20 Jul)
     *   - countDownTime 230-300 dtk = Pertamina minta jeda 4-5 menit
     *   - countDownTime -1e-9 = throttle rusak, blok level akun, HENTIKAN AKUN
     */
    protected const MIN_GAP_SECONDS = 20;
    protected const MAX_GAP_SECONDS = 45;

    /**
     * Berapa kali 500/NOT_ACCEPTABLE beruntun sebelum chain dihentikan.
     * Dinaikkan 3->6 (21 Jul 2026): 500 dari Pertamina sering SPORADIS per-NIK
     * (mis. akun 5 doc#5 sehat — 12 sukses — lalu 3 NIK 500 beruntun mengunci
     * akun 30 menit). Tiap 500 kini juga langsung menandai NIK-nya skip-hari-ini
     * (markSkipForToday) supaya tak diulang, jadi ambang lebih tinggi aman:
     * akun cuma benar-benar berhenti kalau BANYAK NIK 500 beruntun (dokumen
     * habis / gelombang 500 asli), bukan gara-gara segelintir NIK bermasalah.
     */
    protected const MAX_SYSTEM_ERROR_STREAK = 6;

    /**
     * Lama akun didiamkan setelah deret di atas tercapai. Dipendekkan 30->10->3
     * menit (21 Jul 2026): dengan NIK 500 sudah di-skip-hari-ini, jeda panjang
     * tak perlu — kalau memang dokumen habis, run berikutnya cepat pindah ke
     * dokumen order berikutnya; kalau gelombang 500 sisi Pertamina, jeda pendek
     * sudah cukup untuk mencoba lagi tanpa menganggurkan akun terlalu lama.
     */
    protected const COOLDOWN_MINUTES = 3;

    /**
     * Job boleh retry maksimal 1 jam
     * (rate limit ≠ error)
     */
    public function retryUntil(): \DateTime
    {
        return now()->addHour();
    }

    /**
     * =====================================================
     * PAYLOAD
     * =====================================================
     */
    public function __construct(
        public int $account_id,
        public int $data_master_document_id,
        public int $data_nik_input_id,
        public string $run_id = '',
        public int $position = 0,
        public int $total = 0,
    ) {}

    /**
     * Key cache progres per akun.
     */
    public function progressKey(): string
    {
        return "nik_progress:{$this->account_id}";
    }

    /**
     * Key cache jumlah kegagalan transient sebuah NIK hari ini. Dipakai juga
     * oleh AutoInputNikCommand::selectNiks agar NIK yang gagal berulang hari
     * ini tidak menyumbat rotasi dokumen (tanpa menghanguskannya sebulan
     * seperti is_failed).
     */
    public static function dailyFailKey(int $accountId, string $nik): string
    {
        return 'nik_daily_fail:' . $accountId . ':' . now()->toDateString() . ':' . $nik;
    }

    /**
     * Catat satu kegagalan transient NIK ini untuk hari ini (hangus tengah malam).
     */
    protected function markDailyFailure(string $nik): void
    {
        $key = self::dailyFailKey($this->account_id, $nik);

        Cache::put($key, (int) Cache::get($key, 0) + 1, now()->endOfDay());
    }

    /**
     * Langsung istirahatkan NIK ini untuk sisa hari (bukan cuma +1 seperti
     * markDailyFailure). Dipakai untuk 500/NOT_ACCEPTABLE: Pertamina menolak
     * NIK ini sekarang, percuma mengulanginya hari ini — set counter harian
     * ke ambang skip supaya selectNiks langsung mengecualikannya di run
     * berikutnya, sehingga chain maju ke NIK lain alih-alih menggedor NIK
     * bermasalah dan memicu cooldown akun berulang.
     */
    protected function markSkipForToday(string $nik): void
    {
        $key = self::dailyFailKey($this->account_id, $nik);

        Cache::put(
            $key,
            max((int) Cache::get($key, 0), AutoInputNikCommand::MAX_DAILY_FAILS),
            now()->endOfDay(),
        );
    }

    /**
     * Key klaim sebuah NIK — SENGAJA tanpa account_id.
     *
     * Scheduler memanggil tiap akun berurutan tiap menit, tapi chain-nya jalan
     * asinkron: barisan history baru masuk DB beberapa menit setelah NIK
     * dipilih. Tanpa klaim, akun 2 yang menyeleksi di menit yang sama masih
     * melihat NIK yang sudah "diambil" akun 1 sebagai bebas — dan 13 dokumen
     * memang dipakai lebih dari satu akun.
     *
     * Klaim hanya optimisasi penjadwalan; guard bulanan di handle() tetap
     * otoritas terakhir sebelum request ke Pertamina.
     */
    public static function claimKey(string $nik): string
    {
        return 'nik_claim:' . $nik;
    }

    /**
     * Key waktu paling awal akun ini boleh menembak API lagi (pacing).
     */
    public static function nextAllowedKey(int $accountId): string
    {
        return 'nik_next_allowed:' . $accountId;
    }

    /**
     * Key deret kegagalan 500/NOT_ACCEPTABLE beruntun untuk akun ini.
     * Direset begitu ada satu hasil yang membuktikan API masih melayani.
     */
    public static function systemErrorStreakKey(int $accountId): string
    {
        return 'nik_sys_err_streak:' . $accountId;
    }

    /**
     * Key pendinginan akun setelah deret 500 mencapai batas. Dibaca
     * AutoInputNikCommand supaya scheduler tidak mengirim chain baru.
     */
    public static function cooldownKey(int $accountId): string
    {
        return 'nik_cooldown:' . $accountId;
    }

    /**
     * Nolkan deret 500 — dipanggil pada hasil apa pun yang membuktikan API
     * masih melayani akun ini (sukses, atau vonis per-NIK seperti kuota habis).
     */
    protected function resetSystemErrorStreak(): void
    {
        Cache::forget(self::systemErrorStreakKey($this->account_id));
    }

    /**
     * Lepaskan klaim NIK ini — dipanggil begitu NIK mencapai status final
     * (sukses maupun ditolak), supaya tidak menunggu TTL 2 jam.
     */
    protected function releaseClaim(string $nik): void
    {
        Cache::forget(self::claimKey($nik));
    }

    /**
     * Hentikan sisa chain akun ini dan tandai run selesai.
     *
     * Dipakai untuk kondisi level-AKUN (mis. batas harian tercapai) di mana
     * melanjutkan NIK berikutnya pasti gagal juga. Klaim NIK yang sudah
     * terlanjur dipesan chain ini dilepas supaya tidak mengunci NIK sampai TTL
     * habis — run berikutnya boleh memakainya lagi.
     */
    protected function finishChain(string $status = 'stopped'): void
    {
        // `chained` (bukan `chain`) — itu nama properti di trait Queueable.
        // Menulis ke `chain` hanya membuat properti dinamis yang diabaikan,
        // sehingga sisa chain tetap jalan tanpa suara.
        $this->chained = [];

        if ($this->run_id === '') {
            return;
        }

        $state = Cache::get($this->progressKey());

        if (! is_array($state) || ($state['run_id'] ?? null) !== $this->run_id) {
            return;
        }

        foreach ((array) ($state['niks'] ?? []) as $nik) {
            Cache::forget(self::claimKey((string) $nik));
        }

        $state['done']   = (int) ($state['total'] ?? $this->total);
        $state['status'] = $status;

        Cache::put($this->progressKey(), $state, now()->addHours(2));
    }

    /**
     * Tandai NIK posisi ini sudah mencapai status final.
     *
     * Chain berjalan strict berurutan, jadi `done = max(done, position)`
     * aman dari double-count: rate-limit release() tidak memanggil ini,
     * sehingga job yang dijalankan ulang tidak menaikkan progres dua kali.
     * Diabaikan bila run_id tidak cocok (state sudah ditimpa run baru).
     */
    protected function markProgress(): void
    {
        if ($this->run_id === '') {
            return;
        }

        $state = Cache::get($this->progressKey());

        if (! is_array($state) || ($state['run_id'] ?? null) !== $this->run_id) {
            return;
        }

        $state['done'] = max((int) ($state['done'] ?? 0), $this->position);

        Cache::put($this->progressKey(), $state, now()->addHours(2));
    }

    /**
     * =====================================================
     * HANDLE
     * =====================================================
     */
    public function handle(): void
    {
        $today = now()->toDateString();
        $month = now()->format('Y-m');

        /** =========================
         * 1. LOAD DATA
         * ========================= */
        $account = Account::findOrFail($this->account_id);
        $nikInput = DataNikInput::findOrFail($this->data_nik_input_id);

        /** =========================
         * 2. IDEMPOTENCY (1 NIK / HARI)
         *
         * SENGAJA tanpa filter account_id: kuota Pertamina berlaku per NIK
         * (per konsumen), bukan per akun. Dokumen yang sama dipasang ke
         * beberapa akun, jadi memfilter per akun membuat NIK yang sama
         * diinput ulang oleh akun lain di hari yang sama.
         * ========================= */
        $alreadyToday = NikInputHistory::where('nik', $nikInput->nik)
            ->whereDate('input_date', $today)
            ->exists();

        if ($alreadyToday) {
            $this->markProgress();
            $this->releaseClaim($nikInput->nik);
            return; // job dianggap sukses
        }

        /** =========================
         * 3. LIMIT BULANAN (4x / NIK)
         *
         * Juga global lintas akun. Sebelum perbaikan ini tiap akun menghitung
         * dari nol, sehingga 73 NIK sempat diinput 8x bulan Juli 2026
         * (4x oleh akun 1 + 4x oleh akun 2) dan memicu blokir akun.
         * ========================= */
        $monthHistory = NikInputHistory::where('nik', $nikInput->nik)
            ->where('input_month', $month)
            ->get(['is_failed', 'rejected_status']);

        // NIK ditandai isBlocked (mis. status MENINGGAL di Dukcapil) bulan ini ->
        // permanen skip, tidak pernah dicoba ulang (lihat NikBlockedException di bawah).
        if ($monthHistory->contains('is_failed', true)) {
            $this->markProgress();
            $this->releaseClaim($nikInput->nik);
            return;
        }

        // Pertamina sudah menolak NIK ini bulan ini (kuota habis / sudah dipakai
        // pangkalan lain). Jawaban mereka otoritatif — hitungan lokal tidak bisa
        // melihat pembelian di luar sistem ini. Istirahatkan sampai kuota reset.
        if ($monthHistory->contains(fn ($h) => filled($h->rejected_status))) {
            $this->markProgress();
            $this->releaseClaim($nikInput->nik);
            return;
        }

        // Hanya input SUKSES yang dihitung sebagai putaran: baris dengan
        // rejected_status/is_failed adalah penolakan, bukan pembelian.
        $successCount = $monthHistory
            ->filter(fn ($h) => blank($h->rejected_status) && ! $h->is_failed)
            ->count();

        if ($successCount >= AutoInputNikCommand::MAX_ROTATION) {
            $this->markProgress();
            $this->releaseClaim($nikInput->nik);
            return;
        }

        /** =========================
         * 3b. PACING PER AKUN
         *
         * Jarak minimum antar tembakan API supaya polanya tidak seperti bot.
         * release() (bukan sleep) supaya worker tidak ditahan menunggu.
         * ========================= */
        $nextAllowedAt = Cache::get(self::nextAllowedKey($this->account_id));

        if ($nextAllowedAt && now()->lt($nextAllowedAt)) {
            // diffInSeconds() mengembalikan float di Carbon 3 -> cast ke int
            // karena release() menghitung delay dalam detik bulat.
            $this->release(max((int) now()->diffInSeconds($nextAllowedAt), 1));
            return;
        }

        /** =========================
         * 4. RATE LIMIT API
         * 10 HIT / 60 DETIK / ACCOUNT
         * ========================= */
        $rateKey = "pertamina-api-account-{$this->account_id}";

        if (RateLimiter::tooManyAttempts($rateKey, 10)) {

            $delay = max(
                RateLimiter::availableIn($rateKey),
                10
            );

            Log::info('[NIK] Rate limited, releasing job', [
                'account_id' => $this->account_id,
                'nik'        => $nikInput->nik,
                'delay'      => $delay,
            ]);

            $this->release($delay);
            return;
        }

        // KUNCI SLOT RATE LIMIT
        RateLimiter::hit($rateKey, 60);

        /** =========================
         * 5-6. TOKEN + CALL API PERTAMINA
         *
         * Strategi error (agar cocok untuk Bus::chain):
         *  - StockExhaustedException -> log + return: SKIP NIK ini, chain
         *    LANJUT ke NIK berikutnya (stok "dapat dijual" real-time dari
         *    Pertamina bisa lebih rendah dari stockAvailable yang dicek di
         *    awal, jadi ini bukan alasan untuk hentikan seluruh chain).
         *  - error lain (network/verify/submit gagal, mis. 500 dari Pertamina)
         *    -> SKIP NIK ini TANPA history, chain LANJUT ke NIK berikutnya.
         *    NIK otomatis dicoba lagi di run berikutnya (error transient spt
         *    outage 500 tidak boleh menghanguskan NIK sebulan). Hanya
         *    NikBlockedException yang di-flag is_failed permanen bulan ini.
         * ========================= */
        $submitResponse = null;

        try {
            $bearerToken = MerchantTokenService::getOrFetch($account);
            if (! $bearerToken) {
                throw new RuntimeException('Merchant token not available');
            }

            $verifyData = $this->verifyNik($bearerToken, $nikInput->nik, $account);
            $this->registerNikIfNeeded($bearerToken, $nikInput->nik, $verifyData, $account);
            $submitResponse = $this->submitTransaction($bearerToken, $nikInput->nik, $verifyData, $account);
        } catch (StockExhaustedException $e) {
            // Pertamina menolak transaksi NIK ini karena stok "yang dapat
            // dijual" sudah menipis/habis di sisi mereka (independen dari
            // stockAvailable yang dicek sebelum dispatch) -> skip NIK ini
            // saja, lanjut ke NIK berikutnya. Tidak tersimpan di history
            // sehingga akan dicoba lagi di run berikutnya.
            Log::error('[NIK] Stock exhausted untuk NIK ini, skip & lanjut', [
                'account_id'   => $this->account_id,
                'nik'          => $nikInput->nik,
                'nik_input_id' => $this->data_nik_input_id,
                'error'        => $e->getMessage(),
            ]);
            $this->markProgress();
            $this->releaseClaim($nikInput->nik);
            return;
        } catch (NikBlockedException $e) {
            // NIK ditolak permanen oleh Pertamina (mis. status MENINGGAL di Dukcapil).
            // Simpan is_failed=true supaya tidak dihitung sebagai stok terpakai, TAPI
            // tetap tercatat sehingga frontier rotasi per-file bisa maju melewatinya
            // dan NIK ini tidak dicoba ulang lagi bulan ini.
            Log::warning('[NIK] NIK blocked, skip permanen bulan ini', [
                'account_id'   => $this->account_id,
                'nik'          => $nikInput->nik,
                'nik_input_id' => $this->data_nik_input_id,
                'reason'       => $e->getMessage(),
            ]);

            $this->saveHistory($nikInput, $today, $month, isFailed: true, apiResponse: $e->getMessage());

            $this->markProgress();
            $this->releaseClaim($nikInput->nik);
            return;
        } catch (NikQuotaExhaustedException $e) {
            // Pertamina bilang kuota NIK ini sudah habis bulan ini
            // (TRANSACTION_INVALID) atau sudah dipakai di tempat lain
            // (TRANSACTION_ANOMALY). Ini vonis, bukan error sementara.
            //
            // Dulu penolakan ini jatuh ke catch(Throwable) di bawah dan
            // diperlakukan transient, sehingga NIK yang sudah pasti ditolak
            // digedor ulang tiap hari sepanjang bulan (2624 kejadian di log
            // Juli 2026) — pola trafik yang memicu flag anti-abuse.
            Log::warning('[NIK] Kuota NIK habis menurut Pertamina, istirahat sampai bulan depan', [
                'account_id'   => $this->account_id,
                'nik'          => $nikInput->nik,
                'nik_input_id' => $this->data_nik_input_id,
                'status'       => $e->status,
                'response'     => $e->getMessage(),
            ]);

            $this->saveHistory($nikInput, $today, $month, rejectedStatus: $e->status, apiResponse: $e->getMessage());

            // Vonis per-NIK = API jelas masih melayani akun ini -> deret 500 nol.
            $this->resetSystemErrorStreak();

            $this->markProgress();
            $this->releaseClaim($nikInput->nik);
            return;
        } catch (NikNotRegisteredException $e) {
            // NIK tidak terdaftar sebagai penerima subsidi. Berbeda dari kuota
            // habis, ini tidak akan berubah bulan depan -> is_failed permanen.
            Log::warning('[NIK] NIK tidak terdaftar, skip permanen', [
                'account_id'   => $this->account_id,
                'nik'          => $nikInput->nik,
                'nik_input_id' => $this->data_nik_input_id,
                'reason'       => $e->getMessage(),
            ]);

            $this->saveHistory($nikInput, $today, $month, isFailed: true, apiResponse: $e->getMessage());

            $this->markProgress();
            $this->releaseClaim($nikInput->nik);
            return;
        } catch (AccountDailyLimitException $e) {
            // Batas harian AKUN, bukan salah NIK ini. Hentikan sisa chain hari
            // ini dan JANGAN hukum NIK-nya — besok masih layak dicoba.
            Log::error('[NIK] Batas harian akun tercapai, hentikan chain hari ini', [
                'account_id' => $this->account_id,
                'nik'        => $nikInput->nik,
                'response'   => $e->getMessage(),
            ]);

            $this->releaseClaim($nikInput->nik);
            $this->finishChain();
            return;
        } catch (PertaminaSystemErrorException $e) {
            // Tetap transient: NIK TIDAK dicatat permanen & TIDAK dihanguskan
            // sebulan. Yang baru: (a) dihitung ke streak untuk deteksi dokumen
            // habis / gelombang 500, dan (b) NIK-nya langsung diistirahatkan
            // untuk sisa hari supaya tak diulang (markSkipForToday).
            $streak = (int) Cache::get(self::systemErrorStreakKey($this->account_id), 0) + 1;
            Cache::put(self::systemErrorStreakKey($this->account_id), $streak, now()->addHours(2));

            Log::error('[NIK] Pertamina 500/NOT_ACCEPTABLE', [
                'account_id'   => $this->account_id,
                'nik'          => $nikInput->nik,
                'document_id'  => $this->data_master_document_id,
                'streak'       => $streak,
                'error'        => $e->getMessage(),
            ]);

            $this->markSkipForToday($nikInput->nik);
            $this->markProgress();
            $this->releaseClaim($nikInput->nik);

            // Beruntun -> percuma melanjutkan: entah dokumen habis atau
            // Pertamina sedang bermasalah untuk merchant ini. Hentikan chain
            // dan diamkan akun sebentar, JANGAN menggedor lebih kencang
            // (risiko eskalasi jadi hard-block seperti akun 1).
            if ($streak >= self::MAX_SYSTEM_ERROR_STREAK) {
                Cache::put(
                    self::cooldownKey($this->account_id),
                    now()->addMinutes(self::COOLDOWN_MINUTES),
                    now()->addMinutes(self::COOLDOWN_MINUTES),
                );

                Log::error('[NIK] Deret 500 tercapai, chain dihentikan & akun didiamkan', [
                    'account_id'      => $this->account_id,
                    'document_id'     => $this->data_master_document_id,
                    'streak'          => $streak,
                    'cooldown_menit'  => self::COOLDOWN_MINUTES,
                    'catatan'         => 'bisa dokumen habis ATAU gelombang 500 sisi Pertamina',
                ]);

                $this->finishChain();
            }

            return;
        } catch (PertaminaRateLimitedException $e) {
            // Rate limit asli dari Pertamina, bukan soal token -> retry NIK yang
            // sama setelah countDownTime, JANGAN markProgress (bukan sukses/gagal
            // permanen, cuma perlu ditunda).
            //
            // countDownTime dari Pertamina bisa rusak (16 Jul 2026: -1e-9 ->
            // dibulatkan jadi 10 dtk) sehingga retry tiap 10 dtk menggedor API
            // berjam-jam dan malah menjaga jendela limit tetap hidup. Kalau
            // countdown tidak masuk akal, pakai backoff menaik per attempt
            // (60s, 120s, ... maks 600s) supaya limitnya diberi waktu dingin.
            $delay = $e->retryAfterSeconds > 10
                ? $e->retryAfterSeconds
                : min(60 * max($this->attempts(), 1), 600);

            // Sengaja ERROR (bukan warning): production LOG_LEVEL=error, dan
            // loop 429 yang tak terlihat pernah menyembunyikan akar masalah
            // seharian (16 Jul 2026). Body respons ikut supaya kelihatan
            // TOO_MANY_REQUEST biasa vs DAILY_LIMIT_TRANSACTION.
            Log::error('[NIK] Pertamina rate-limit, retry setelah countdown', [
                'account_id' => $this->account_id,
                'nik'        => $nikInput->nik,
                'delay'      => $delay,
                'attempt'    => $this->attempts(),
                'response'   => $e->getMessage(),
            ]);
            $this->release($delay);
            return;
        } catch (TokenStaleException $e) {
            // Token dianggap valid secara lokal (cache TTL 14 menit) tapi ditolak
            // Pertamina eksplisit 401/403. Token sudah di-invalidate() di titik
            // lempar exception ini, jadi NIK berikutnya di chain otomatis pakai
            // token segar -> skip NIK ini saja & lanjut (bukan retry NIK yang sama),
            // konsisten dengan aturan: error selain rate-limit = skip & lanjut.
            Log::error('[NIK] Token basi terdeteksi, skip NIK ini & lanjut', [
                'account_id'   => $this->account_id,
                'nik'          => $nikInput->nik,
                'nik_input_id' => $this->data_nik_input_id,
                'reason'       => $e->getMessage(),
            ]);
            $this->markDailyFailure($nikInput->nik);
            $this->markProgress();
            $this->releaseClaim($nikInput->nik);
            return;
        } catch (Throwable $e) {
            Log::error('[NIK] Job skip (error, lanjut NIK berikutnya)', [
                'account_id'   => $this->account_id,
                'nik'          => $nikInput->nik,
                'nik_input_id' => $this->data_nik_input_id,
                'error'        => $e->getMessage(),
            ]);

            // Tidak disimpan ke history: error generic dianggap transient
            // (mis. outage 500 Pertamina), NIK akan dicoba lagi di run
            // berikutnya. Flag is_failed hanya untuk NikBlockedException.
            $this->markDailyFailure($nikInput->nik);
            $this->markProgress();
            // Klaim dilepas: NIK ini gagal transient dan akan dicoba lagi di run
            // berikutnya, jadi tidak perlu mengunci akun lain sampai TTL habis.
            // (PertaminaRateLimitedException sengaja TIDAK melepas klaim — di
            // sana NIK yang sama akan di-retry oleh job ini juga.)
            $this->releaseClaim($nikInput->nik);
            return; // skip & lanjut: chain tetap jalan ke NIK berikutnya
        } finally {
            // Jeda berlaku untuk SETIAP percobaan API, apa pun hasilnya.
            //
            // Versi pertama fix ini hanya memasang jeda setelah input sukses.
            // Akibatnya saat dokumen buntu (semua NIK dibalas 500) gerbang
            // pacing tidak pernah menyala dan chain menembak beruntun — 20 Jul
            // 2026 akun 3: 6 NIK dalam 4 detik, lalu kena 429. Justru pola yang
            // ingin dihilangkan. Ditaruh di finally supaya tidak ada satu pun
            // jalur keluar (return/throw/release) yang bisa melewatinya.
            Cache::put(
                self::nextAllowedKey($this->account_id),
                now()->addSeconds(random_int(self::MIN_GAP_SECONDS, self::MAX_GAP_SECONDS)),
                now()->addHour(),
            );
        }

        /** =========================
         * 7. SAVE HISTORY (SAFE UNIQUE)
         * ========================= */
        $this->saveHistory($nikInput, $today, $month, apiResponse: $submitResponse);

        /** =========================
         * 8. UPDATE ACCOUNT (LEGACY)
         * ========================= */
        $account->update([
            'last_nik_input'  => $nikInput->nik,
            'last_update_api' => now(),
        ]);

        // Input berhasil -> deret 500 nol lagi.
        $this->resetSystemErrorStreak();

        // Jeda ke tembakan berikutnya sudah dipasang di blok finally di atas
        // (berlaku untuk semua hasil, bukan hanya sukses).
        $this->markProgress();
        $this->releaseClaim($nikInput->nik);

        Log::info('[NIK] Job success', [
            'account_id' => $this->account_id,
            'nik'        => $nikInput->nik,
        ]);
    }

    /**
     * Simpan history input NIK hari ini (safe terhadap unique constraint).
     *
     * is_failed=true dipakai HANYA untuk NIK yang ditolak permanen oleh
     * Pertamina (NikBlockedException, mis. isBlocked Dukcapil) -> tidak
     * dihitung stok terpakai, tapi tercatat sehingga run berikutnya bulan
     * ini langsung skip NIK tersebut. Error generic/transient tidak dicatat.
     */
    protected function saveHistory(
        DataNikInput $nikInput,
        string $today,
        string $month,
        bool $isFailed = false,
        ?string $rejectedStatus = null,
        ?string $apiResponse = null,
    ): void {
        try {
            NikInputHistory::firstOrCreate(
                [
                    'account_id' => $this->account_id,
                    'nik'        => $nikInput->nik,
                    'input_date' => $today,
                ],
                [
                    'data_master_document_id' => $this->data_master_document_id,
                    'data_nik_input_id'       => $nikInput->id,
                    'input_month'             => $month,
                    'is_failed'               => $isFailed,
                    'rejected_status'         => $rejectedStatus,
                    'rejected_at'             => $rejectedStatus ? now() : null,
                    'api_response'            => $apiResponse,
                ]
            );
        } catch (QueryException $e) {
            // safety net unique constraint
            if ($e->getCode() !== '23505') {
                throw $e;
            }
        }
    }

    /**
     * =====================================================
     * API HELPERS
     * =====================================================
     */

    /**
     * Header yang meniru web app resmi MAP (subsiditepatlpg.mypertamina.id).
     *
     * 16 Jul 2026: endpoint transaksi utk akun 1 di-flag anti-abuse Pertamina
     * (dipicu badai retry). Terbukti empiris (uji Postman user, IP & akun sama):
     * request "mentah" (UA PostmanRuntime, tanpa Origin/Referer) ditolak 429,
     * sedangkan request dari browser lolos. Menyelaraskan header agar konsisten
     * dgn front-end resmi yang memang kita otomasikan -> bukan bypass keamanan,
     * tapi menyamakan bentuk request dengan klien sah akun ini.
     */
    protected function browserHeaders(string $bearerToken): array
    {
        return [
            'Authorization'    => 'Bearer ' . $bearerToken,
            'Accept'           => 'application/json, text/plain, */*',
            'Accept-Language'  => 'id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7',
            'Origin'           => 'https://subsiditepatlpg.mypertamina.id',
            'Referer'          => 'https://subsiditepatlpg.mypertamina.id/',
            'User-Agent'       => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 '
                                . '(KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
            'sec-ch-ua'        => '"Chromium";v="123", "Not:A-Brand";v="8", "Google Chrome";v="123"',
            'sec-ch-ua-mobile' => '?0',
            'sec-ch-ua-platform' => '"Linux"',
            'Sec-Fetch-Dest'   => 'empty',
            'Sec-Fetch-Mode'   => 'cors',
            'Sec-Fetch-Site'   => 'cross-site',
        ];
    }

    /**
     * Terjemahkan penolakan tegas Pertamina menjadi exception yang tepat.
     *
     * Dipanggil oleh verifyNik() DAN submitTransaction(), dan HARUS dijalankan
     * sebelum pemeriksaan `$res->failed()` — semua penolakan ini datang sebagai
     * HTTP 400, jadi kalau failed() diperiksa lebih dulu semuanya tertelan
     * menjadi RuntimeException generic lalu diperlakukan sebagai error
     * sementara dan di-retry berhari-hari.
     *
     * `NOT_ACCEPTABLE` ("Terjadi kesalahan pada sistem kami") sengaja TIDAK
     * ditangani di sini: itu gelombang 500 dari sisi Pertamina, bukan vonis
     * atas NIK-nya, jadi tetap transient.
     *
     * @param  array<mixed>|null  $json
     */
    protected function assertNotRejected(?array $json, string $context, string $body): void
    {
        $status  = $json['status'] ?? null;
        $message = $json['message'] ?? null;

        // Kuota NIK habis bulan ini / sudah dipakai pangkalan lain.
        if (in_array($status, ['TRANSACTION_INVALID', 'TRANSACTION_ANOMALY'], true)) {
            throw new NikQuotaExhaustedException("{$context}: {$body}", (string) $status);
        }

        // NIK tidak terdaftar sebagai penerima subsidi -> permanen.
        if ($status === 'NOT_FOUND' || $message === 'Data pelanggan tidak ditemukan') {
            throw new NikNotRegisteredException("{$context}: {$body}");
        }

        // Batas harian di level akun -> hentikan chain, jangan hukum NIK.
        if ($status === 'DAILY_LIMIT_TRANSACTION' || $message === 'Transaksi melebihi batas harian') {
            throw new AccountDailyLimitException("{$context}: {$body}");
        }

        // Gangguan sisi Pertamina / dokumen habis. Tetap transient (NIK tidak
        // dihanguskan), tapi dihitung supaya chain bisa berhenti lebih awal.
        if ($status === 'NOT_ACCEPTABLE' || $message === 'Terjadi kesalahan pada sistem kami') {
            throw new PertaminaSystemErrorException("{$context}: {$body}");
        }
    }

    protected function verifyNik(string $bearerToken, string $nik, Account $account): array
    {
        $res = Http::withHeaders($this->browserHeaders($bearerToken))->get(
            'https://api-map.my-pertamina.id/customers/v2/verify-nik',
            ['nationalityId' => $nik]
        );

        $json = $res->json();

        if (($json['code'] ?? null) === 429 || ($json['status'] ?? null) === 'TOO_MANY_REQUEST') {
            $delay = max((int) ($json['data']['countDownTime'] ?? 10), 10);

            throw new PertaminaRateLimitedException('Verify-NIK rate limited: ' . $res->body(), $delay);
        }

        if (in_array($res->status(), [401, 403], true)) {
            // Token cache masih dianggap valid secara lokal (TTL 14 menit) tapi
            // sudah kedaluwarsa di sisi Pertamina -> buang supaya attempt
            // berikutnya fetch token baru, bukan mengulang token basi ini.
            MerchantTokenService::invalidate($account);
            throw new TokenStaleException('Verify-NIK: ' . $res->body());
        }

        // Sebelum failed(): penolakan tegas datang sebagai HTTP 400.
        $this->assertNotRejected($json, 'Verify-NIK ditolak', $res->body());

        if ($res->failed()) {
            throw new RuntimeException('Verify-NIK failed: ' . $res->body());
        }

        $data = $res->json('data');

        if ($data['isBlocked'] ?? false) {
            throw new NikBlockedException(
                (string) ($data['reason']['message'] ?? 'NIK isBlocked oleh Pertamina')
            );
        }

        foreach (['token', 'familyIdEncrypted', 'name'] as $key) {
            if (blank($data[$key] ?? null)) {
                throw new RuntimeException("Verify-NIK missing {$key}");
            }
        }

        return $data;
    }

    /**
     * Ekstrak tanggal lahir dari NIK (digit 7-12).
     * Digit 7-8 = hari, 9-10 = bulan, 11-12 = tahun (2 digit).
     * Tahun: jika > dua digit terakhir tahun sekarang → 19xx, else → 20xx.
     */
    protected function extractDobFromNik(string $nik): string
    {
        $day   = (int) substr($nik, 6, 2);
        $month = substr($nik, 8, 2);
        $year2 = (int) substr($nik, 10, 2);

        // Perempuan: digit hari di NIK ditambah 40 → kurangi kembali
        if ($day > 40) {
            $day -= 40;
        }

        $year = $year2 > (int) date('y') ? (1900 + $year2) : (2000 + $year2);

        return sprintf('%04d-%s-%02d', $year, $month, $day);
    }

    /**
     * Jika salah satu field agreement belum terpenuhi, hit API policy (3x GET)
     * lalu POST terms-consent, kemudian hit API registrasi supaya konsumen
     * terdaftar sebelum transaksi dilanjutkan.
     */
    protected function registerNikIfNeeded(string $bearerToken, string $nik, array $verifyData, Account $account): void
    {
        // Kanal 'nik' punya level sendiri (default debug); LOG_LEVEL global di
        // produksi = error sehingga Log::info/warning biasa tidak pernah
        // tertulis dan alur ini tampak tidak pernah jalan.
        $log = Log::channel('nik');

        $flags = [
            'isAgreedAgreement'          => $verifyData['isAgreedAgreement'] ?? null,
            'isAgreedReceiveInformation' => $verifyData['isAgreedReceiveInformation'] ?? null,
            'isAgreedTerms'              => $verifyData['isAgreedTerms'] ?? null,
            'isAgreedTermsConditions'    => $verifyData['isAgreedTermsConditions'] ?? null,
            'isCompleted'                => $verifyData['isCompleted'] ?? null,
        ];

        $allAgreed = ($verifyData['isAgreedAgreement'] ?? false) === true
            && ($verifyData['isAgreedReceiveInformation'] ?? false) === true
            && ($verifyData['isAgreedTerms'] ?? false) === true
            && ($verifyData['isAgreedTermsConditions'] ?? false) === true
            && ($verifyData['isCompleted'] ?? false) === true;

        $log->debug('[NIK] Cek agreement sebelum registrasi', [
            'account_id' => $this->account_id,
            'nik'        => $nik,
            'flags'      => $flags,
            'all_agreed' => $allAgreed,
        ]);

        if ($allAgreed) {
            return;
        }

        $log->info('[NIK] Ada field agreement belum lengkap, mulai alur policy+consent+registrasi', [
            'account_id' => $this->account_id,
            'nik'        => $nik,
        ]);

        // Step 1: GET policy untuk 3 contentType (seolah-olah pengguna membaca policy)
        foreach ([1, 2, 3] as $contentType) {
            $policyRes = Http::withHeaders($this->browserHeaders($bearerToken))->get(
                'https://api-map.my-pertamina.id/general/agreement/v1/policy',
                ['userType' => '3', 'contentType' => (string) $contentType],
            );

            $log->info('[NIK] GET policy', [
                'account_id'  => $this->account_id,
                'nik'         => $nik,
                'contentType' => $contentType,
                'http_status' => $policyRes->status(),
            ]);
        }

        // Step 2: POST terms-consent (menyetujui agreement & terms)
        $consentRes = Http::withHeaders($this->browserHeaders($bearerToken))
            ->post(
                'https://api-map.my-pertamina.id/general/customer-service/v1/terms-consent',
                [
                    'customerType'       => 'Rumah Tangga',
                    'historyIdAgreement' => 20,
                    'historyIdTerm'      => 19,
                    'nationalityId'      => $nik,
                ],
            );

        if ($consentRes->failed()) {
            $log->warning('[NIK] POST terms-consent gagal, tetap lanjut ke registrasi', [
                'account_id'  => $this->account_id,
                'nik'         => $nik,
                'http_status' => $consentRes->status(),
                'response'    => $consentRes->body(),
            ]);
        } else {
            $log->info('[NIK] POST terms-consent berhasil', [
                'account_id' => $this->account_id,
                'nik'        => $nik,
                'response'   => $consentRes->body(),
            ]);
        }

        // Step 3: PUT registrasi.
        //
        // HARUS PUT, bukan POST: endpoint ini menjawab 405 Method Not Allowed
        // untuk POST (terbukti 13 Ags 2026, semua NIK). Dikonfirmasi dari
        // devtools web resmi — PUT dengan body {"pob":...,"dob":...} -> 200 OK.
        $dob = $this->extractDobFromNik($nik);
        $pob = NikRegionHelper::getCityName($nik) ?? 'Jombang';

        $log->info('[NIK] Hit API registrasi', [
            'account_id' => $this->account_id,
            'nik'        => $nik,
            'dob'        => $dob,
            'pob'        => $pob,
        ]);

        $res = Http::withHeaders($this->browserHeaders($bearerToken))
            ->put(
                'https://api-map.my-pertamina.id/customers/v3/registration/' . $nik . '/Rumah%20Tangga',
                ['pob' => $pob, 'dob' => $dob],
            );

        if ($res->failed()) {
            $log->warning('[NIK] API registrasi gagal, tetap lanjut ke submit', [
                'account_id'  => $this->account_id,
                'nik'         => $nik,
                'http_status' => $res->status(),
                'response'    => $res->body(),
            ]);
        } else {
            $log->info('[NIK] API registrasi berhasil', [
                'account_id' => $this->account_id,
                'nik'        => $nik,
                'response'   => $res->body(),
            ]);
        }
    }

    protected function submitTransaction(
        string $bearerToken,
        string $nik,
        array $verifyData,
        Account $account
    ): string {
        $payload = [
            'quantity'          => '1',
            'token'             => (string) $verifyData['token'],
            'nationalityId'     => $nik,
            'familyIdEncrypted' => (string) $verifyData['familyIdEncrypted'],
            'category'          => $verifyData['customerTypes'][0]['name'] ?? 'Rumah Tangga',
            'sourceTypeId'      => '1',
            'name'              => (string) $verifyData['name'],
            'channelinject'     => $verifyData['channelInject'] ?? 'tnp2k',
        ];

        $res = Http::withHeaders($this->browserHeaders($bearerToken))->asMultipart()->post(
            'https://api-map.my-pertamina.id/general/v3/transactions',
            $payload
        );

        $json = $res->json();

        // Rate limit ASLI dari Pertamina (di luar RateLimiter internal kita
        // 10 hit/60 detik/akun) -> BUKAN soal token, jangan invalidate. Tunggu
        // sesuai countDownTime lalu retry NIK yang sama dengan token yang sama.
        if (($json['code'] ?? null) === 429 || ($json['status'] ?? null) === 'TOO_MANY_REQUEST') {
            $delay = max((int) ($json['data']['countDownTime'] ?? 10), 10);

            throw new PertaminaRateLimitedException('Submit-transaction rate limited: ' . $res->body(), $delay);
        }

        // Token masih dianggap valid secara lokal (cache TTL 14 menit) tapi
        // ditolak Pertamina di step submit -> baru bisa dipastikan token basi
        // kalau balasannya eksplisit 401/403 (lihat juga verifyNik()).
        if (in_array($res->status(), [401, 403], true)) {
            MerchantTokenService::invalidate($account);
            throw new TokenStaleException('Submit-transaction: ' . $res->body());
        }

        if (
            ($json['code'] ?? null) === 400
            && ($json['message'] ?? null) === 'Transaksi melebihi stok yang dapat dijual'
        ) {
            throw new StockExhaustedException((string) $json['message']);
        }

        // Sebelum pemeriksaan generik di bawah: penolakan tegas (kuota habis,
        // NIK tak terdaftar, batas harian akun) punya penanganan sendiri.
        $this->assertNotRejected($json, 'Submit-transaction ditolak', $res->body());

        if (($json['code'] ?? null) !== 200 || ($json['status'] ?? null) !== 'OK') {
            throw new RuntimeException(json_encode($json));
        }

        // Response mentah sukses -> disimpan ke history (kolom api_response).
        return $res->body();
    }

    /**
     * =====================================================
     * FAILED HANDLER
     * =====================================================
     */
    public function failed(Throwable $e): void
    {
        Log::error('[NIK] Job failed permanently', [
            'account_id' => $this->account_id,
            'nik_input_id' => $this->data_nik_input_id,
            'error' => $e->getMessage(),
        ]);
    }
}
