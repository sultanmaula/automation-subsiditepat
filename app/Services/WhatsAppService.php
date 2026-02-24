<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WhatsAppService
{
    public static function send(string $number, string $message): void
    {
        Http::post('http://host.docker.internal:3001/send', [
            'number' => $number,
            'message' => $message,
        ]);
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

    public static function dailyRecap(array $recapItems, int $totalAllQuantity)
    {
        $message = "📊 *REKAPAN HARIAN*\n"
                . "📅 " . now()->format('d M Y') . "\n"
                . "━━━━━━━━━━━━━━━━━━\n\n";

        foreach ($recapItems as $i => $item) {
            $no = $i + 1;
            $message .= "*{$no}. {$item['storeName']}*\n"
                    . "```"
                    . "Email      : {$item['email']}\n"
                    . "Sisa Stock : {$item['stockAvailable']}\n"
                    . "Input      : {$item['totalQuantity']}\n"
                    . "Status     : {$item['status']}\n"
                    . "```\n";
        }

        $message .= "━━━━━━━━━━━━━━━━━━\n"
                . "📦 *Total Input Hari Ini: {$totalAllQuantity} tabung*\n"
                . "📋 *Total Account: " . count($recapItems) . "*\n\n"
                . "⏰ Waktu: " . now()->format('d M Y H:i') . " WIB\n\n"
                . "_Rekapan otomatis dari sistem Automation LPG_ 🤖";

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
