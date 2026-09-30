<?php

return [
    'path' => env('BACKUP_PATH', storage_path('app/private/database-backups')),
    'external_path' => env('BACKUP_EXTERNAL_PATH'),
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),
    'mysql_dump_binary' => env('MYSQL_DUMP_BINARY', 'mysqldump'),
    'postgres_dump_binary' => env('POSTGRES_DUMP_BINARY', 'pg_dump'),
];
