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

    public static function reminderStock($email, $stockAvailable)
    {
        $message = "*Halo {$email} 👋*\n\n"
                . "Ini adalah _notifikasi otomatis_ dari sistem *Automation LPG*.\n\n"
                . "📌 *Detail Input:*\n"
                . "```"
                . "Email       : {$email}\n"
                . "Sisa Stock  : {$stockAvailable}\n"
                . "Waktu       : " . now()->format('d M Y H:i') . " WIB\n"
                . "```\n"
                . "⚠️ *NB / Perhatian!*\n"
                . "_⏰ Segera lakukan input hari ini_ *maksimal pukul 19.00 WIB* ❗\n\n"
                . "Terima kasih 🙏";

        return $message;
    }
}
