<?php

namespace App\Services;

use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramService
{
    public static function send(string $message): void
    {
        Telegram::bot('auto_lpg_bot')->sendMessage([
            'chat_id' => config('telegram.bots.auto_lpg_bot.chat_id'),
            'text' => $message,
            'parse_mode' => 'HTML',
        ]);
    }
}
