<?php

namespace App\Filament\Resources\Accounts\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;

class AccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required(),
                TextInput::make('pin')
                    ->numeric()
                    ->required(),
                TextEntry::make('bearerToken')
                    ->visibleOn('view')
                    ->placeholder('-')
                    ->default(fn ($record) => Cache::get("merchant_api_token_{$record->email}"))
                    ->copyable(),
                TextEntry::make('last_update_api')
                    ->label('Last Update API')
                    ->placeholder('-')
                    ->formatStateUsing(fn ($state) =>
                        \Illuminate\Support\Carbon::parse($state)
                            ->timezone('Asia/Jakarta')
                            ->format('M d, Y H:i:s')
                    )
                    ->visibleOn('view'),
            ]);
    }
}
