<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'backup:database {--path= : Direktori penyimpanan cadangan lokal} {--retain= : Jumlah hari retensi}';

    protected $description = 'Mencadangkan database dan membersihkan file yang melewati masa retensi.';

    public function handle(): int
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $databaseConfig = $connection->getConfig();
        $retentionDays = (int) ($this->option('retain') ?: config('backup.retention_days', 14));
        if ($retentionDays < 1 || $retentionDays > 365) {
            $this->error('Retensi harus di antara 1 dan 365 hari.');

            return self::FAILURE;
        }

        $directory = $this->option('path') ?: config('backup.path');
        File::ensureDirectoryExists($directory, 0700);
        $filename = 'backup-'.$driver.'-'.now()->format('Ymd-His');
        $extension = match ($driver) {
            'sqlite' => 'sqlite',
            'mysql', 'mariadb' => 'sql',
            'pgsql' => 'dump',
            default => null,
        };
        if (! $extension) {
            $this->error("Driver database {$driver} belum didukung untuk backup otomatis.");

            return self::FAILURE;
        }

        $target = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename.'.'.$extension;
        $backupCreated = false;
        try {
            match ($driver) {
                'sqlite' => $this->backupSqlite((string) $databaseConfig['database'], $target),
                'mysql', 'mariadb' => $this->backupMysql($databaseConfig, $target),
                'pgsql' => $this->backupPostgres($databaseConfig, $target),
            };
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $target .= '.gz';
            }
            @chmod($target, 0600);
            $backupCreated = true;
            $this->copyExternal($target);
            $this->removeExpired($directory, $retentionDays);
            $external = config('backup.external_path');
            if ($external) {
                $this->removeExpired($external, $retentionDays);
            }
        } catch (Throwable $exception) {
            if (! $backupCreated) {
                if (is_file($target)) {
                    @unlink($target);
                }
                @unlink($target.'.tmp');
                @unlink($target.'.gz');
            }
            $this->error('Backup gagal: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup berhasil: '.$target);

        return self::SUCCESS;
    }

    private function backupSqlite(string $database, string $target): void
    {
        if ($database === ':memory:' || ! is_file($database)) {
            throw new \RuntimeException('Backup SQLite memerlukan database file; database in-memory tidak dapat dicadangkan.');
        }
        $quotedPath = str_replace("'", "''", $target);
        DB::statement("VACUUM INTO '{$quotedPath}'");
    }

    private function backupMysql(array $database, string $target): void
    {
        $command = [
            config('backup.mysql_dump_binary', 'mysqldump'),
            '--single-transaction', '--routines', '--triggers', '--events', '--hex-blob',
            '--user='.$database['username'], '--result-file='.$target,
        ];
        if (! empty($database['unix_socket'])) {
            $command[] = '--socket='.$database['unix_socket'];
        } else {
            $command[] = '--host='.$database['host'];
            $command[] = '--port='.$database['port'];
        }
        $command[] = $database['database'];
        $process = new Process($command, base_path(), ['MYSQL_PWD' => (string) $database['password']], null, 3600);
        $this->runProcess($process, 'mysqldump');
        $this->gzipFile($target);
    }

    private function backupPostgres(array $database, string $target): void
    {
        $command = [
            config('backup.postgres_dump_binary', 'pg_dump'),
            '--no-password', '--format=custom', '--file='.$target,
            '--username='.$database['username'], '--host='.$database['host'], '--port='.$database['port'], $database['database'],
        ];
        $process = new Process($command, base_path(), ['PGPASSWORD' => (string) $database['password']], null, 3600);
        $this->runProcess($process, 'pg_dump');
    }

    private function runProcess(Process $process, string $binary): void
    {
        $process->run();
        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            throw new \RuntimeException("{$binary} gagal dijalankan. ".($message ?: 'Pastikan utilitas tersedia dan konfigurasi database benar.'));
        }
    }

    private function gzipFile(string $sqlPath): void
    {
        $input = fopen($sqlPath, 'rb');
        $output = gzopen($sqlPath.'.tmp', 'wb9');
        if (! $input || ! $output) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                gzclose($output);
            }
            throw new \RuntimeException('File SQL sementara tidak dapat dikompresi.');
        }
        try {
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false || gzwrite($output, $chunk) === false) {
                    throw new \RuntimeException('Kompresi file backup tidak selesai.');
                }
            }
        } finally {
            fclose($input);
            gzclose($output);
        }
        if (! rename($sqlPath.'.tmp', $sqlPath.'.gz')) {
            @unlink($sqlPath.'.tmp');
            throw new \RuntimeException('File backup terkompresi tidak dapat disimpan.');
        }
        @unlink($sqlPath);
    }

    private function copyExternal(string $target): void
    {
        $externalDirectory = config('backup.external_path');
        if (! $externalDirectory) {
            return;
        }
        if (! is_dir($externalDirectory) || ! is_writable($externalDirectory)) {
            throw new \RuntimeException('Lokasi BACKUP_EXTERNAL_PATH harus berupa mount eksternal yang tersedia dan dapat ditulis.');
        }
        $externalTarget = rtrim($externalDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.basename($target);
        if (! copy($target, $externalTarget)) {
            @unlink($externalTarget);
            throw new \RuntimeException('Cadangan tidak dapat disalin ke lokasi eksternal.');
        }
        @chmod($externalTarget, 0600);
    }

    private function removeExpired(string $directory, int $retentionDays): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $cutoff = Carbon::now()->subDays($retentionDays)->getTimestamp();
        foreach (glob(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'backup-*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
