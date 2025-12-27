<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::create([
            'name' => 'Sultan Maula Chamzah',
            'email' => 'sultanmaulachamzah@gmail.com',
            'password' => Hash::make('Sult@n354'),
        ]);

        /*
        * =======================
        * Account Merchant Seeder 
        * =======================
        */
        Account::create([
            'email' => 'vauzismcg@gmail.com',
            'pin' => '181818',
        ]);

        Account::create([
            'email' => 'smcluluk@gmail.com',
            'pin' => '181818',
        ]);

        Account::create([
            'email' => 'nurulsmcg@gmail.com',
            'pin' => '181818',
        ]);

        Account::create([
            'email' => 'solikinmmmg@gmail.com',
            'pin' => '181818',
        ]);

        Account::create([
            'email' => 'rodneyevanz@gmail.com',
            'pin' => '181818',
        ]);
    }
}
