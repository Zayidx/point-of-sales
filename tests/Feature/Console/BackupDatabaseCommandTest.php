<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;

class BackupDatabaseCommandTest extends TestCase
{
    public function test_sqlite_database_file_is_backed_up_and_readable(): void
    {
        $originalDatabase = config('database.connections.sqlite.database');
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pos-backup-test-'.uniqid();
        $databasePath = $directory.DIRECTORY_SEPARATOR.'source.sqlite';
        $externalDirectory = $directory.DIRECTORY_SEPARATOR.'offsite';
        $originalExternalPath = config('backup.external_path');
        File::ensureDirectoryExists($directory);
        File::ensureDirectoryExists($externalDirectory);
        File::put($databasePath, '');
        File::put($directory.DIRECTORY_SEPARATOR.'backup-stale.sqlite', 'expired');
        touch($directory.DIRECTORY_SEPARATOR.'backup-stale.sqlite', now()->subDays(20)->getTimestamp());
        config(['database.connections.sqlite.database' => $databasePath]);
        config(['backup.external_path' => $externalDirectory]);
        DB::purge('sqlite');

        try {
            DB::connection('sqlite')->statement('CREATE TABLE backup_probe (value TEXT NOT NULL)');
            DB::connection('sqlite')->table('backup_probe')->insert(['value' => 'siap dipulihkan']);

            $this->artisan('backup:database', ['--path' => $directory])->assertExitCode(0);

            $backups = collect(File::files($directory))
                ->map(fn (\SplFileInfo $file) => $file->getPathname())
                ->filter(fn (string $path) => str_starts_with(basename($path), 'backup-sqlite-'));
            $this->assertCount(1, $backups);
            $backup = new PDO('sqlite:'.$backups->first());
            $this->assertSame('siap dipulihkan', $backup->query('SELECT value FROM backup_probe')->fetchColumn());
            $this->assertFileExists($externalDirectory.DIRECTORY_SEPARATOR.basename($backups->first()));
            $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.'backup-stale.sqlite');
        } finally {
            DB::purge('sqlite');
            config(['database.connections.sqlite.database' => $originalDatabase]);
            config(['backup.external_path' => $originalExternalPath]);
            DB::purge('sqlite');
            File::deleteDirectory($directory);
        }
    }

    public function test_in_memory_database_is_not_written_as_a_backup(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pos-memory-backup-test-'.uniqid();
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');

        try {
            DB::connection('sqlite')->getPdo();
            $this->artisan('backup:database', ['--path' => $directory])->assertExitCode(1);
            $this->assertSame([], File::files($directory));
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
