<?php

namespace Elliptic\Backfill\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;

class ImportService
{
    protected SqlDumpTransformer $sqlDumpTransformer;

    public function __construct(?SqlDumpTransformer $sqlDumpTransformer = null)
    {
        $this->sqlDumpTransformer = $sqlDumpTransformer ?? new SqlDumpTransformer;
    }

    /**
     * Import a SQL dump file into the local database using the mysql CLI.
     * This is the fastest possible import method.
     */
    public function importSqlDump(string $table, string $sqlFilePath, bool $isDelta): int
    {
        if (! file_exists($sqlFilePath)) {
            throw new RuntimeException("SQL dump file not found: {$sqlFilePath}");
        }

        $fileSize = filesize($sqlFilePath);
        if ($fileSize === 0) {
            return 0;
        }

        $connection = config('database.default');
        $dbConfig = config("database.connections.{$connection}");
        $driver = $dbConfig['driver'] ?? 'mysql';

        if ($driver !== 'mysql') {
            // Fallback: parse and execute SQL statements via PDO for non-MySQL
            return $this->importSqlViaPhp($table, $sqlFilePath, $isDelta);
        }

        $host = $dbConfig['host'] ?? '127.0.0.1';
        $port = $dbConfig['port'] ?? '3306';
        $username = $dbConfig['username'];
        $password = $dbConfig['password'] ?? '';
        $database = $dbConfig['database'];

        $importPath = $sqlFilePath.'.'.bin2hex(random_bytes(8)).'.import.sql';

        try {
            $this->sqlDumpTransformer->writeImportFile(
                $table,
                $sqlFilePath,
                $importPath,
                $isDelta,
            );

            if (! $isDelta) {
                $this->disableForeignKeyChecks();

                try {
                    DB::table($table)->truncate();
                } finally {
                    $this->enableForeignKeyChecks();
                }
            }

            $args = array_filter([
                'mysql',
                '--host='.$host,
                '--port='.$port,
                '--user='.$username,
                $password ? '--password='.$password : null,
                $database,
            ]);

            $process = Process::fromShellCommandline(
                implode(' ', array_map('escapeshellarg', $args)).' < '.escapeshellarg($importPath)
            );
            $process->setTimeout(
                max(1, (int) config('backfill.client.import_timeout', 3600))
            );
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException(
                    "MySQL import failed for table '{$table}': ".$process->getErrorOutput()
                );
            }
        } finally {
            @unlink($importPath);
        }

        return DB::table($table)->count();
    }

    /**
     * Fallback: import SQL via PHP for non-MySQL databases (SQLite, PostgreSQL).
     */
    protected function importSqlViaPhp(string $table, string $sqlFilePath, bool $isDelta): int
    {
        $sql = file_get_contents($sqlFilePath);
        $sql = str_replace("`_backfill_{$table}`", "`{$table}`", $sql);

        $this->disableForeignKeyChecks();

        try {
            if (! $isDelta) {
                DB::table($table)->truncate();
            }

            // Split by semicolons and execute each statement
            $statements = array_filter(
                array_map('trim', explode(";\n", $sql)),
                fn ($s) => ! empty($s) && ! str_starts_with($s, '--')
            );

            foreach ($statements as $statement) {
                if ($isDelta) {
                    $statement = str_replace('INSERT INTO', 'INSERT OR REPLACE INTO', $statement);
                }
                DB::unprepared($statement);
            }
        } finally {
            $this->enableForeignKeyChecks();
        }

        return DB::table($table)->count();
    }

    /**
     * Get the columns that exist in the local table.
     */
    public function getLocalColumns(string $table): array
    {
        return Schema::getColumnListing($table);
    }

    protected function disableForeignKeyChecks(): void
    {
        match (DB::getDriverName()) {
            'mysql' => DB::statement('SET FOREIGN_KEY_CHECKS=0'),
            'sqlite' => DB::statement('PRAGMA foreign_keys = OFF'),
            'pgsql' => DB::statement('SET session_replication_role = replica'),
            default => null,
        };
    }

    protected function enableForeignKeyChecks(): void
    {
        match (DB::getDriverName()) {
            'mysql' => DB::statement('SET FOREIGN_KEY_CHECKS=1'),
            'sqlite' => DB::statement('PRAGMA foreign_keys = ON'),
            'pgsql' => DB::statement('SET session_replication_role = DEFAULT'),
            default => null,
        };
    }
}
