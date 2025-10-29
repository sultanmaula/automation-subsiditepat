<?php

namespace App\Filament\Resources\DataNikInputs\Pages;

use App\Filament\Resources\DataNikInputs\DataNikInputResource;
use App\Models\DataMasterDocument;
use App\Models\DataNikInput;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
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

                    try {
                        DB::transaction(function () use ($handle, &$header, &$columnIndices, &$line, &$created, &$updated, &$rowsProcessed, &$sequence, $document): void {
                            while (($row = fgetcsv($handle)) !== false) {
                                $line++;
                                $row = array_map(static fn ($value) => is_string($value) ? trim($value) : $value, $row);

                                if ($header === null) {
                                    $header = array_map(
                                        static fn ($column) => Str::of($column)
                                            ->lower()
                                            ->squish()
                                            ->replace([' ', '-'], '_')
                                            ->replaceMatches('/[^a-z0-9_]/', '')
                                            ->toString(),
                                        $row
                                    );

                                    $requiredColumns = ['nik', 'nama', 'alamat'];

                                    foreach ($requiredColumns as $column) {
                                        $index = array_search($column, $header, true);

                                        if ($index === false) {
                                            throw ValidationException::withMessages([
                                                'csv_file' => 'CSV header must contain columns: NIK, Nama, dan Alamat.',
                                            ]);
                                        }

                                        $columnIndices[$column] = $index;
                                    }

                                    continue;
                                }

                                if (count(array_filter($row, static fn ($value) => $value !== null && $value !== '')) === 0) {
                                    continue;
                                }

                                $nik = $row[$columnIndices['nik']] ?? null;

                                if (! is_string($nik) || trim($nik) === '') {
                                    throw ValidationException::withMessages([
                                        'csv_file' => "Baris {$line}: kolom NIK wajib diisi.",
                                    ]);
                                }

                                $name = $row[$columnIndices['nama']] ?? null;
                                $address = $row[$columnIndices['alamat']] ?? null;

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
