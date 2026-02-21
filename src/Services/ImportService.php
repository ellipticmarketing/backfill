<?php

namespace Elliptic\Backfill\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;

class ImportService
{
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

        // If full import, truncate the table first
        if (! $isDelta) {
            $this->disableForeignKeyChecks();
            DB::table($table)->truncate();
            $this->enableForeignKeyChecks();
        }

        // The dump from the server uses the temp table/db name in INSERT statements.
        // We need to rewrite those to point at the real table name.
        // The sed-like approach: pipe through a replacement, or just load directly
        // since mysqldump with --no-create-info just produces INSERT statements.

        // Build the mysql import command
        // The dump may reference a temp table name — we'll handle this with a
        // SQL wrapper that renames inserts via a temp-to-real table mapping.
        $sql = $this->prepareSqlForImport($table, $sqlFilePath, $isDelta);
        $importPath = $sqlFilePath . '.import.sql';
        file_put_contents($importPath, $sql);

        $args = array_filter([
            'mysql',
            '--host=' . $host,
            '--port=' . $port,
            '--user=' . $username,
            $password ? '--password=' . $password : null,
            $database,
        ]);

        $process = Process::fromShellCommandline(
            implode(' ', array_map('escapeshellarg', $args)) . ' < ' . escapeshellarg($importPath)
        );
        $process->setTimeout(config('backfill.client.timeout', 300));
        $process->run();

        @unlink($importPath);

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                "MySQL import failed for table '{$table}': " . $process->getErrorOutput()
            );
        }

        // Approximate row count from the imported data
        return DB::table($table)->count();
    }

    /**
     * Prepare the SQL dump for import into the local database.
     *
     * The server's dump may reference temp table names (e.g., `_backfill_users`).
     * This method rewrites the SQL so INSERTs target the correct local table.
     * For delta mode, it converts INSERT INTO to INSERT ... ON DUPLICATE KEY UPDATE.
     */
    protected function prepareSqlForImport(string $table, string $sqlFilePath, bool $isDelta): string
    {
        $sql = file_get_contents($sqlFilePath);

        // Replace any temp table references with the real table name
        // The dump may contain `_backfill_{table}` or just `{table}` depending on strategy
        $sql = str_replace("`_backfill_{$table}`", "`{$table}`", $sql);

        if ($isDelta) {
            // Wrap in a session that converts INSERTs to upserts
            $wrapped = "SET FOREIGN_KEY_CHECKS=0;\n";

            // Use REPLACE INTO instead of INSERT INTO for upsert behavior
            $sql = str_replace('INSERT INTO', 'REPLACE INTO', $sql);
            $wrapped .= $sql;
            $wrapped .= "\nSET FOREIGN_KEY_CHECKS=1;\n";

            return $wrapped;
        }

        // Full import: disable FK checks, run the dump, re-enable
        return "SET FOREIGN_KEY_CHECKS=0;\n" . $sql . "\nSET FOREIGN_KEY_CHECKS=1;\n";
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
