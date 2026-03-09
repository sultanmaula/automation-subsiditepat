<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    public static function send(string $number, string $message): void
    {
        Http::post('http://host.docker.internal:3001/send', [
            'number' => $number,
            'message' => $message,
        ]);
    }

    public static function sendImage(string $number, string $imagePath, string $caption = ''): void
    {
        $url = env('WHATSAPP_IMAGE_ENDPOINT', 'http://host.docker.internal:3001/send-image');

        $response = Http::attach('image', file_get_contents($imagePath), basename($imagePath))
            ->post($url, [
                'number' => $number,
                'caption' => $caption,
            ]);

        if ($response->failed()) {
            Log::error('[WhatsApp] sendImage failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'url' => $url,
                'number' => $number,
            ]);
        }
    }

    public static function reminderStock($name, $email, $stockAvailable, $inputToday)
    {
        $message = "━━━━━━━━━━━━━━━━━━\n"
                . "🔔 *REMINDER STOK LPG*\n"
                . "━━━━━━━━━━━━━━━━━━\n\n"
                . "Halo *{$name}* 👋\n"
                . "Berikut update stok kamu hari ini:\n\n"
                . "📋 *Detail Akun:*\n"
                . "┌─────────────────────\n"
                . "│ 📧 Email  : {$email}\n"
                . "│ 📦 Stok   : *{$stockAvailable}* tabung\n"
                . "│ ✅ Input  : *{$inputToday}* tabung\n"
                . "│ 🕐 Waktu  : " . now()->format('d M Y H:i') . " WIB\n"
                . "└─────────────────────\n\n"
                . "⚠️ *PENTING — JANGAN SAMPAI TERLEWAT!*\n"
                . "⏰ Batas input hari ini: *pukul 19.00 WIB*\n"
                . "📦 Maksimal input per hari: *200 tabung*\n\n"
                . "━━━━━━━━━━━━━━━━━━\n"
                . "_🤖 Pesan otomatis dari Automation LPG_\n"
                . "Terima kasih 🙏";

        return $message;
    }

    public static function dailyRecap(array $recapItems, int $totalAllQuantity, array $overLimitCustomers = [])
    {
        $message = "📊 *REKAPAN HARIAN*\n"
                . "📅 " . now()->format('d M Y') . "\n"
                . "━━━━━━━━━━━━━━━━━━\n\n";

        foreach ($recapItems as $i => $item) {
            $no = $i + 1;
            $dobelText = ($item['overLimitCount'] ?? 0) > 0
                ? "{$item['overLimitCount']} orang"
                : 'Tidak ada';
            $message .= "*{$no}. {$item['storeName']}*\n"
                    . "```"
                    . "Email      : {$item['email']}\n"
                    . "Sisa Stock : {$item['stockAvailable']}\n"
                    . "Input >2x  : {$dobelText}\n"
                    . "Input      : {$item['totalQuantity']}\n"
                    . "Status     : {$item['status']}\n"
                    . "```\n";
        }

        $message .= "━━━━━━━━━━━━━━━━━━\n"
                . "📦 *Total Input Hari Ini: {$totalAllQuantity} tabung*\n"
                . "📋 *Total Akun: " . count($recapItems) . "*\n\n";

        if (!empty($overLimitCustomers)) {
            $message .= "━━━━━━━━━━━━━━━━━━\n"
                    . "🚨 *PERHATIAN!*\n"
                    . "👥 Ditemukan akun yang input *lebih dari 2x* hari ini:\n";

            foreach ($overLimitCustomers as $storeName => $grouped) {
                $message .= "\n🏪 *{$storeName}*\n```";
                foreach ($grouped as $total => $count) {
                    $message .= "Input {$total}x = {$count} orang\n";
                }
                $message .= "```";
            }

            $message .= "\n━━━━━━━━━━━━━━━━━━\n";
        } else {
            $message .= "✅ *Tidak ada input lebih dari 2x hari ini*\n\n";
        }

        $message .= "⏰ Waktu: " . now()->format('d M Y H:i') . " WIB\n\n"
                . "_Rekapan otomatis dari sistem Automation LPG_ 🤖";

        return $message;
    }

    public static function monthlyRecap(array $recapItems, int $totalAllQuantity, string $startDate, string $endDate, array $overLimitCustomers = [])
    {
        $periodStart = \Carbon\Carbon::parse($startDate)->format('d M Y');
        $periodEnd = \Carbon\Carbon::parse($endDate)->format('d M Y');

        $message = "📊 *REKAPAN BULANAN*\n"
                . "📅 Periode: {$periodStart} - {$periodEnd}\n"
                . "━━━━━━━━━━━━━━━━━━\n\n";

        foreach ($recapItems as $i => $item) {
            $no = $i + 1;
            $dobelText = $item['overLimitCount'] > 0
                ? "{$item['overLimitCount']} orang"
                : 'Tidak ada';
            $message .= "*{$no}. {$item['storeName']}*\n"
                    . "```"
                    . "Email      : {$item['email']}\n"
                    . "Input >4x  : {$dobelText}\n"
                    . "Total Input: {$item['totalQuantity']}\n"
                    . "Status     : {$item['status']}\n"
                    . "```\n";
        }

        $message .= "━━━━━━━━━━━━━━━━━━\n"
                . "📦 *Total Input Bulan Ini: {$totalAllQuantity} tabung*\n"
                . "📋 *Total Akun: " . count($recapItems) . "*\n\n";

        // Bagian customer yang input > 4x
        if (!empty($overLimitCustomers)) {
            $message .= "━━━━━━━━━━━━━━━━━━\n"
                    . "🚨 *PERHATIAN!*\n"
                    . "👥 Ditemukan akun yang input *lebih dari 4x* bulan ini:\n";

            foreach ($overLimitCustomers as $storeName => $grouped) {
                $message .= "\n🏪 *{$storeName}*\n```";
                foreach ($grouped as $total => $count) {
                    $message .= "Input {$total}x = {$count} orang\n";
                }
                $message .= "```";
            }

            $message .= "\n━━━━━━━━━━━━━━━━━━\n";
        } else {
            $message .= "✅ *Tidak ada dobel input (>4x) bulan ini*\n\n";
        }

        $message .= "⏰ Waktu: " . now()->format('d M Y H:i') . " WIB\n\n"
                . "_Rekapan bulanan otomatis dari sistem Automation LPG_ 🤖";

        return $message;
    }

    public static function errorStock($email)
    {
        $message = "🔔 *PEMBERITAHUAN*\n\n"
                . "Halo {$email} 👋\n\n"
                . "Saat ini sistem stok sedang tidak tersedia.\n"
                . "_Silakan coba kembali beberapa saat lagi._\n\n"
                . "Terima kasih 🙏";

        return $message;
    }
}
