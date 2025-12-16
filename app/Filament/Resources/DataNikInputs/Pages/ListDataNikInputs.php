<?php

namespace App\Filament\Resources\DataNikInputs\Pages;

use App\Filament\Resources\DataNikInputs\DataNikInputResource;
use App\Jobs\GenerateRandomNikJob;
use App\Models\Account;
use App\Models\DataMasterDocument;
use App\Models\DataNikInput;
use App\Services\NikRandomGenerator;
use App\Support\RegionData;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListDataNikInputs extends ListRecords
{
    protected static string $resource = DataNikInputResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('randomGenerator')
                ->label('Data Generator Random')
                ->icon('heroicon-o-sparkles')
                ->color('info')
                ->modalWidth('lg')
                ->modalSubmitActionLabel('Mulai Generate')
                ->form([
                    Radio::make('document_mode')
                        ->label('Dokumen')
                        ->options([
                            'new' => 'Buat baru',
                            'existing' => 'Pakai data lama',
                        ])
                        ->default('new')
                        ->inline()
                        ->required(),
                    TextInput::make('document_name')
                        ->label('Nama Dokumen')
                        ->default(fn (): string => 'Random Generator ' . now()->format('Y-m-d H:i'))
                        ->required(fn (Get $get): bool => $get('document_mode') === 'new')
                        ->visible(fn (Get $get): bool => $get('document_mode') === 'new')
                        ->maxLength(255),
                    Select::make('data_master_document_id')
                        ->label('Pilih Dokumen Lama')
                        ->placeholder('-')
                        ->options(static fn (): array => DataMasterDocument::query()
                            ->orderByDesc('created_at')
                            ->pluck('original_name', 'id')
                            ->toArray())
                        ->searchable()
                        ->preload()
                        ->required(fn (Get $get): bool => $get('document_mode') === 'existing')
                        ->visible(fn (Get $get): bool => $get('document_mode') === 'existing'),
                    Select::make('account_id')
                        ->label('Gunakan Akun')
                        ->placeholder('-')
                        ->options(static fn (): array => Account::query()
                            ->orderBy('email')
                            ->pluck('email', 'id')
                            ->toArray())
                        ->searchable()
                        ->required(),
                    Select::make('province_code')
                        ->label('Provinsi')
                        ->placeholder('-')
                        ->options(fn (): array => RegionData::provinces())
                        ->live()
                        ->required(),
                    Select::make('regency_code')
                        ->label('Kabupaten/Kota')
                        ->placeholder('-')
                        ->options(fn (Get $get): array => RegionData::regencies((string) $get('province_code')))
                        ->live()
                        ->required(),
                    Select::make('district_code')
                        ->label('Kecamatan')
                        ->placeholder('-')
                        ->options(fn (Get $get): array => RegionData::districts((string) $get('province_code'), (string) $get('regency_code')))
                        ->required(),
                    TextInput::make('amount')
                        ->label('Jumlah NIK yang digenerate')
                        ->numeric()
                        ->default(200)
                        ->minValue(1)
                        ->maxValue(500),
                ])
                ->action(function (array $data): void {
                    $account = Account::query()->find($data['account_id'] ?? null);

                    if (! $account) {
                        Notification::make()
                            ->title('Akun tidak ditemukan.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $region = RegionData::findRegion(
                        (string) ($data['province_code'] ?? ''),
                        (string) ($data['regency_code'] ?? ''),
                        (string) ($data['district_code'] ?? '')
                    );

                    if (! $region) {
                        Notification::make()
                            ->title('Data daerah tidak valid.')
                            ->body('Pastikan Provinsi, Kabupaten/Kota, dan Kecamatan dipilih dari data JSON.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $amount = (int) ($data['amount'] ?? 200);
                    $amount = max(1, min($amount, 500));

                    $documentMode = $data['document_mode'] ?? 'new';
                    $document = null;
                    $disk = config('filesystems.default', 'public');

                    if ($documentMode === 'existing') {
                        $document = DataMasterDocument::query()->find($data['data_master_document_id'] ?? null);

                        if (! $document) {
                            Notification::make()
                                ->title('Dokumen tidak ditemukan.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $disk = $document->disk ?? $disk;

                        if ($document->path && ! Storage::disk($disk)->exists($document->path)) {
                            Storage::disk($disk)->put($document->path, "nik,name,address\n");
                        }
                    } else {
                        $directory = 'data-master-documents';
                        $storedName = now()->format('YmdHis') . '_random_nik_' . Str::uuid() . '.csv';
                        $storedPath = $directory . '/' . $storedName;

                        Storage::disk($disk)->put($storedPath, "nik,name,address\n");

                        $document = DataMasterDocument::create([
                            'original_name' => $data['document_name'] ?? 'Data Generator Random',
                            'stored_name' => $storedName,
                            'disk' => $disk,
                            'path' => $storedPath,
                            'extension' => 'csv',
                            'size' => Storage::disk($disk)->size($storedPath),
                            'mime_type' => 'text/csv',
                        ]);
                    }

                    $generator = app(NikRandomGenerator::class);
                    $generated = [];
                    $attempts = 0;
                    $maxAttempts = $amount * 10;

                    while (count($generated) < $amount && $attempts < $maxAttempts) {
                        $attempts++;
                        $nik = $generator->generate($region['province_code'], $region['regency_code'], $region['district_code']);

                        if (isset($generated[$nik]) || DataNikInput::where('nik', $nik)->exists()) {
                            continue;
                        }

                        $generated[$nik] = $nik;
                    }

                    $nikList = array_values($generated);

                    if ($nikList === []) {
                        Notification::make()
                            ->title('Gagal menghasilkan NIK.')
                            ->body('Silakan coba lagi.')
                            ->danger()
                            ->send();

                        Storage::disk($disk)->delete($storedPath);
                        $document->delete();

                        return;
                    }
                    
                    foreach (array_values($nikList) as $index => $nik) {
                        GenerateRandomNikJob::dispatch($account->id, $document->id, $nik)->delay(now()->addSeconds(6));
                    }

                    Notification::make()
                        ->title('Data Generator Random dimulai.')
                        ->body(sprintf('%d NIK akan diproses untuk %s - %s. Hasil akan otomatis disimpan di dokumen %s.', count($nikList), $region['regency_name'], $region['district_name'], $document->original_name))
                        ->success()
                        ->send();
                }),
            Action::make('importCsv')
                ->label('Import CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->modalSubmitActionLabel('Import')
                ->modalWidth('md')
                ->form([
                    FileUpload::make('csv_file')
                        ->label('File CSV')
                        ->acceptedFileTypes([
                            'text/csv',
                            'text/plain',
                            'application/vnd.ms-excel',
                            'application/csv',
                        ])
                        ->helperText('Gunakan header NIK, Nama, Alamat.')
                        ->required()
                        ->storeFiles(false),
                ])
                ->action(function (array $data): void {
                    $file = $data['csv_file'] ?? null;

                    if (! $file instanceof TemporaryUploadedFile) {
                        throw ValidationException::withMessages([
                            'csv_file' => 'The uploaded file is invalid.',
                        ]);
                    }

                    $originalName = $file->getClientOriginalName() ?? $file->getFilename();
                    $extension = $file->getClientOriginalExtension() ?: 'csv';
                    $size = $file->getSize();
                    $mimeType = $file->getMimeType();
                    $disk = config('filesystems.default', 'public');
                    $directory = 'data-master-documents';
                    $storedName = now()->format('YmdHis') . '_' . Str::uuid() . '.' . $extension;
                    $storedPath = $file->storeAs($directory, $storedName, $disk);

                    if (! $storedPath) {
                        throw ValidationException::withMessages([
                            'csv_file' => 'File gagal disimpan, silakan coba lagi.',
                        ]);
                    }

                    $document = DataMasterDocument::create([
                        'original_name' => $originalName,
                        'stored_name' => $storedName,
                        'disk' => $disk,
                        'path' => $storedPath,
                        'extension' => $extension,
                        'size' => $size,
                        'mime_type' => $mimeType,
                    ]);

                    $handle = Storage::disk($disk)->readStream($storedPath);

                    if ($handle === false) {
                        throw ValidationException::withMessages([
                            'csv_file' => 'File CSV tidak dapat dibuka.',
                        ]);
                    }

                    $header = null;
                    $columnIndices = [];
                    $line = 0;
                    $created = 0;
                    $updated = 0;
                    $rowsProcessed = 0;
                    $sequence = 0;
                    $requiredColumns = ['nik', 'nama', 'alamat'];

                    try {
                        DB::transaction(function () use ($handle, &$header, &$columnIndices, &$line, &$created, &$updated, &$rowsProcessed, &$sequence, $document, $requiredColumns): void {
                            while (($row = fgetcsv($handle)) !== false) {
                                $line++;
                                $row = array_map(static fn ($value) => is_string($value) ? trim($value) : $value, $row);
                                $hasContent = count(array_filter(
                                    $row,
                                    static fn ($value) => $value !== null && $value !== ''
                                )) > 0;

                                if (! $hasContent) {
                                    continue;
                                }

                                if ($header === null) {
                                    $normalizedHeader = array_map(
                                        static fn ($column) => Str::of($column)
                                            ->replace("\xEF\xBB\xBF", '')
                                            ->lower()
                                            ->squish()
                                            ->replace([' ', '-'], '_')
                                            ->replaceMatches('/[^a-z0-9_]/', '')
                                            ->toString(),
                                        $row
                                    );

                                    $columnIndices = [];
                                    $missingColumns = [];

                                    foreach ($requiredColumns as $column) {
                                        $index = array_search($column, $normalizedHeader, true);

                                        if ($index === false) {
                                            $missingColumns[] = $column;
                                            continue;
                                        }

                                        $columnIndices[$column] = $index;
                                    }

                                    if ($missingColumns === []) {
                                        $header = $normalizedHeader;
                                        continue;
                                    }

                                    $looksLikeDataRow = count($row) >= count($requiredColumns)
                                        && isset($row[0])
                                        && preg_match('/^\d{8,}$/', preg_replace('/\D/', '', (string) $row[0]));

                                    if ($looksLikeDataRow) {
                                        $header = $requiredColumns;
                                        $columnIndices = array_flip($requiredColumns);
                                    } else {
                                        throw ValidationException::withMessages([
                                            'csv_file' => 'CSV header must contain columns: NIK, Nama, dan Alamat.',
                                        ]);
                                    }
                                }

                                if ($columnIndices === []) {
                                    throw ValidationException::withMessages([
                                        'csv_file' => 'CSV header must contain columns: NIK, Nama, dan Alamat.',
                                    ]);
                                }

                                $nikIndex = $columnIndices['nik'] ?? 0;
                                $nameIndex = $columnIndices['nama'] ?? 1;
                                $addressIndex = $columnIndices['alamat'] ?? 2;

                                $nik = $row[$nikIndex] ?? null;

                                if (! is_string($nik) || trim($nik) === '') {
                                    throw ValidationException::withMessages([
                                        'csv_file' => "Baris {$line}: kolom NIK wajib diisi.",
                                    ]);
                                }

                                $name = $row[$nameIndex] ?? null;
                                $address = $row[$addressIndex] ?? null;

                                $sequence++;

                                $record = DataNikInput::updateOrCreate(
                                    ['nik' => trim($nik)],
                                    [
                                        'name' => is_string($name) ? trim($name) : '',
                                        'address' => is_string($address) ? trim($address) : null,
                                        'data_master_document_id' => $document->id,
                                        'order' => $sequence,
                                    ]
                                );

                                if ($record->wasRecentlyCreated) {
                                    $created++;
                                } elseif ($record->wasChanged()) {
                                    $updated++;
                                }

                                $rowsProcessed++;
                            }
                        });

                        if ($header === null) {
                            throw ValidationException::withMessages([
                                'csv_file' => 'File CSV kosong atau tidak memiliki header.',
                            ]);
                        }

                        if ($rowsProcessed === 0) {
                            throw ValidationException::withMessages([
                                'csv_file' => 'Tidak ada baris data yang ditemukan untuk diimport.',
                            ]);
                        }

                        Notification::make()
                            ->title('Import CSV berhasil')
                            ->body("{$created} data baru dan {$updated} data diperbarui dari {$document->original_name}.")
                            ->success()
                            ->send();
                    } catch (\Throwable $exception) {
                        Storage::disk($disk)->delete($storedPath);
                        $document->delete();

                        throw $exception;
                    } finally {
                        if (is_resource($handle)) {
                            fclose($handle);
                        }
                    }
                }),
        ];
    }
}
