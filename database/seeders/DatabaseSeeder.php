<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\DataMasterDocument;
use App\Models\DataNikInput;
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
            'name' => 'Admin LPG',
            'email' => 'adminlpg@gmail.com',
            'password' => Hash::make('admin123'),
            'role' => 'lpg'
        ]);

        User::create([
            'name' => 'Admin Workshop',
            'email' => 'adminworkshop@gmail.com',
            'password' => Hash::make('admin123'),
            'role' => 'workshop'
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

        /*
        * =======================
        * Data NIK Inputs Seeder
        * =======================
        */
        $csvDir = base_path('data_csv');

        if (is_dir($csvDir)) {
            $files = glob($csvDir . '/*.csv') ?: [];

            foreach ($files as $filePath) {
                $fileName = basename($filePath);

                $document = DataMasterDocument::firstOrCreate(
                    ['path' => "data_csv/{$fileName}"],
                    [
                        'original_name' => $fileName,
                        'stored_name' => $fileName,
                        'disk' => 'local',
                        'extension' => 'csv',
                        'size' => filesize($filePath) ?: null,
                        'mime_type' => 'text/csv',
                    ]
                );

                $handle = fopen($filePath, 'r');

                if ($handle === false) {
                    continue;
                }

                $sequence = 0;

                while (($row = fgetcsv($handle)) !== false) {
                    if (!is_array($row) || count($row) === 0) {
                        continue;
                    }

                    $nik = trim((string) ($row[0] ?? ''));
                    $nik = ltrim($nik, "\xEF\xBB\xBF");

                    $name = trim((string) ($row[1] ?? ''));

                    if ($nik === '' || $name === '') {
                        continue;
                    }

                    $sequence++;

                    $addressParts = array_map(
                        static fn ($value) => trim((string) $value),
                        array_slice($row, 2)
                    );
                    $addressParts = array_filter($addressParts, static fn ($value) => $value !== '');
                    $address = $addressParts ? implode(', ', $addressParts) : null;
                    
                    DataNikInput::updateOrCreate(
                        ['nik' => $nik],
                        [
                            'data_master_document_id' => $document->id,
                            'name' => $name,
                            'address' => $address,
                            'order' => $sequence,
                        ]
                    );
                }

                fclose($handle);
            }
        }
    }
}
