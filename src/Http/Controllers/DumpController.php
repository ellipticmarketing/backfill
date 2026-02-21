<?php

namespace Elliptic\Backfill\Http\Controllers;

use Elliptic\Backfill\Services\RowLimiterService;
use Elliptic\Backfill\Services\SanitizationService;
use Elliptic\Backfill\Services\SchemaService;
use Elliptic\Backfill\Services\SubsetResolverService;
use Elliptic\Backfill\Services\TempDatabaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;

class DumpController
{
    public function __invoke(
        Request $request,
        string $table,
        SchemaService $schema,
        TempDatabaseService $tempDb,
        SanitizationService $sanitizer,
        RowLimiterService $limiter,
    ): StreamedResponse {
        $excludedTables = config('backfill.exclude_tables', []);

        if (in_array($table, $excludedTables)) {
            abort(403, "Table '{$table}' is excluded from sync.");
        }

        $allTables = $schema->getTables($excludedTables);

        if (! in_array($table, $allTables)) {
            abort(404, "Table '{$table}' not found.");
        }

        $after = $request->input('after'); // ISO 8601 timestamp for delta sync

        // Prepare: copy → sanitize → limit, all in a temp space
        $tempDb->prepare($table);

        try {
            // Apply sanitization rules via SQL UPDATE
            $sanitizeRules = config("backfill.sanitize.{$table}", []);
            if (! empty($sanitizeRules)) {
                $sanitizer->sanitize($table, $sanitizeRules, $tempDb);
            }

            // Apply row limits via stateless subset queries
            $limits = config('backfill.limits', []);
            if (! empty($limits)) {
                $resolver = new SubsetResolverService($schema, $limits, $tempDb->getSourceDatabase());
                $limiter->apply($table, $tempDb, $resolver, $schema);
            }

            // If delta, delete rows older than the "after" timestamp
            if ($after && $schema->hasTimestamps($table)) {
                $qualified = $tempDb->qualifiedTableName($table);
                DB::statement(
                    "DELETE FROM {$qualified} WHERE `created_at` < ? AND `updated_at` < ?",
                    [$after, $after]
                );
            }

            // Run mysqldump on the temp copy and stream gzipped output
            $dumpArgs = $this->buildMysqldumpArgs($tempDb, $table);
            $primaryKey = $schema->getPrimaryKey($table);
            $hasTimestamps = $schema->hasTimestamps($table);

            return new StreamedResponse(function () use ($dumpArgs, $tempDb, $table, $primaryKey, $hasTimestamps) {
                // First, send a small JSON header line with metadata, then the SQL dump
                $meta = json_encode([
                    'primary_key' => $primaryKey,
                    'has_timestamps' => $hasTimestamps,
                ]);
                echo $meta . "\n";
                echo "-- BEGIN SQL DUMP --\n";
                flush();

                $process = new Process($dumpArgs);
                $process->setTimeout(config('backfill.server.dump_timeout', 3600));

                // Stream stdout directly to the HTTP response
                $process->run(function ($type, $buffer) {
                    if ($type === Process::OUT) {
                        echo $buffer;
                        flush();
                    }
                });

                if (! $process->isSuccessful()) {
                    echo "\n-- DUMP ERROR: " . $process->getErrorOutput() . " --\n";
                }

                $tempDb->cleanup($table);
            }, 200, [
                'Content-Type' => 'application/octet-stream',
                'X-Backfill-Table' => $table,
                'X-Backfill-Format' => 'sqldump',
                'Cache-Control' => 'no-cache',
                'Content-Disposition' => "attachment; filename=\"{$table}.sql\"",
            ]);
        } catch (\Throwable $e) {
            $tempDb->cleanup($table);

            throw $e;
        }
    }

    /**
     * Build the mysqldump command arguments for a single table in the temp space.
     */
    protected function buildMysqldumpArgs(TempDatabaseService $tempDb, string $table): array
    {
        $connection = config('database.default');
        $dbConfig = config("database.connections.{$connection}");

        // Use alternate credentials if configured
        $username = config('backfill.server.temp_username') ?? $dbConfig['username'];
        $password = config('backfill.server.temp_password') ?? $dbConfig['password'];
        $host = $dbConfig['host'] ?? '127.0.0.1';
        $port = $dbConfig['port'] ?? '3306';

        $tempDatabase = $tempDb->getTempDatabaseName();
        $sourceDatabase = $tempDb->getSourceDatabase();

        // If using "tables" strategy, dump from the source DB but only the temp table
        if ($tempDb->getStrategy() === 'tables') {
            $tempTableName = '_backfill_' . $table;

            return array_filter([
                'mysqldump',
                '--host=' . $host,
                '--port=' . $port,
                '--user=' . $username,
                $password ? '--password=' . $password : null,
                '--single-transaction',
                '--quick',
                '--no-create-info', // We handle schema separately
                '--skip-lock-tables',
                '--complete-insert',
                '--skip-comments',
                '--net-buffer-length=32768',
                $sourceDatabase,
                $tempTableName,
            ]);
        }

        // "database" strategy — dump from the temp database
        return array_filter([
            'mysqldump',
            '--host=' . $host,
            '--port=' . $port,
            '--user=' . $username,
            $password ? '--password=' . $password : null,
            '--single-transaction',
            '--quick',
            '--no-create-info',
            '--skip-lock-tables',
            '--complete-insert',
            '--skip-comments',
            '--net-buffer-length=32768',
            $tempDatabase,
            $table,
        ]);
    }
}
