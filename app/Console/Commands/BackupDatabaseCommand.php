<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class BackupDatabaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:backup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backup database PostgreSQL ke storage lokal, nama file mengandung tanggal dan jam backup';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $disk = Storage::disk('local');
        $timestamp = Carbon::now()->timezone('Asia/Jakarta')->format('d-m-Y_H-i');
        $relativePath = "backups/db-backup-{$timestamp}.sql";
        $disk->makeDirectory('backups');

        $absolutePath = $disk->path($relativePath);

        $result = Process::env(['PGPASSWORD' => config('database.connections.pgsql.password')])
            ->run([
                'pg_dump',
                '-h', config('database.connections.pgsql.host'),
                '-p', (string) config('database.connections.pgsql.port'),
                '-U', config('database.connections.pgsql.username'),
                '-F', 'p',
                '-f', $absolutePath,
                config('database.connections.pgsql.database'),
            ]);

        if (! $result->successful()) {
            $this->error('Backup database gagal: ' . $result->errorOutput());
            Log::error('[DB Backup] Gagal backup database', ['error' => $result->errorOutput()]);
            return self::FAILURE;
        }

        chmod(dirname($absolutePath), 0755);
        chmod($absolutePath, 0644);

        $this->info("Backup database berhasil disimpan di: {$absolutePath}");
        Log::info('[DB Backup] Backup database berhasil', ['path' => $absolutePath]);

        $this->pruneOldBackups($disk);

        return self::SUCCESS;
    }

    /**
     * Hapus file backup yang lebih tua dari 30 hari.
     */
    protected function pruneOldBackups(\Illuminate\Filesystem\FilesystemAdapter $disk): void
    {
        $cutoff = Carbon::now()->subDays(30);

        foreach ($disk->files('backups') as $file) {
            if (Carbon::createFromTimestamp($disk->lastModified($file))->lt($cutoff)) {
                $disk->delete($file);
                $this->info("Backup lama dihapus: {$file}");
                Log::info('[DB Backup] Backup lama dihapus', ['path' => $file]);
            }
        }
    }
}
