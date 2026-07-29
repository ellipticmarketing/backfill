<?php

namespace Elliptic\Backfill\Http\Controllers;

use Elliptic\Backfill\Services\NativeSqlDumpService;
use Elliptic\Backfill\Services\RowLimiterService;
use Elliptic\Backfill\Services\SanitizationService;
use Elliptic\Backfill\Services\SchemaService;
use Elliptic\Backfill\Services\ServerRequirementsService;
use Elliptic\Backfill\Services\SubsetResolverService;
use Elliptic\Backfill\Services\TempDatabaseService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
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
        NativeSqlDumpService $nativeDumper,
        ServerRequirementsService $requirements,
    ): Response {
        $excludedTables = config('backfill.exclude_tables', []);

        if (in_array($table, $excludedTables)) {
            abort(403, "Table '{$table}' is excluded from sync.");
        }

        $allTables = $schema->getTables($excludedTables);

        if (! in_array($table, $allTables)) {
            abort(404, "Table '{$table}' not found.");
        }

        $after = $request->input('after'); // ISO 8601 timestamp for delta sync
        $dumpLock = null;

        try {
            $mysqldumpPath = $requirements->ensureRequirementsAreMet();

            $limits = config('backfill.limits', []);
            $resolver = null;
            $primaryKeyColumn = $schema->getPrimaryKey($table)[0] ?? null;

            if (! empty($limits)) {
                if ($primaryKeyColumn === null && ! empty($limits[$table])) {
                    throw new RuntimeException(
                        "Cannot limit table '{$table}' because it does not have a primary key."
                    );
                }

                if ($primaryKeyColumn !== null) {
                    $resolver = new SubsetResolverService(
                        $schema,
                        $limits,
                        $tempDb->getSourceDatabase(),
                        config('backfill.limit_mode', 'table'),
                    );
                }
            }

            $sanitizeRules = config("backfill.sanitize.{$table}", []);
            $primaryKey = $schema->getPrimaryKey($table);
            $columns = $schema->getColumns($table);
            $hasTimestamps = $schema->hasTimestamps($table);
            $chunkContext = null;
            $dumpLock = Cache::lock(
                "backfill:dump:{$table}",
                (int) config('backfill.server.dump_timeout', 3600) + 60,
            );

            try {
                $dumpLock->block(
                    max(0, (int) config('backfill.server.lock_wait_seconds', 30))
                );
            } catch (LockTimeoutException) {
                return response()->json([
                    'error' => "Another Backfill request is already processing '{$table}'.",
                ], Response::HTTP_LOCKED);
            }

            if (
                $request->boolean('chunked')
                && $after === null
                && count($primaryKey) === 1
                && $schema->isIntegerColumn($table, $primaryKeyColumn)
            ) {
                $resolver ??= new SubsetResolverService(
                    $schema,
                    $limits,
                    $tempDb->getSourceDatabase(),
                    config('backfill.limit_mode', 'table'),
                );

                $chunkSize = max(1, (int) config('backfill.server.chunk_size', 5000));
                $cursor = $this->numericCursor($request->query('cursor'), 'cursor');
                $highWater = $this->numericCursor(
                    $request->query('high_water'),
                    'high_water',
                ) ?? $tempDb->getSourceHighWaterMark($table, $primaryKeyColumn);
                $highWater ??= '0';

                $chunkContext = [
                    'keep_query' => $this->buildChunkKeepQuery(
                        $resolver->buildKeepQuery($table),
                        $primaryKeyColumn,
                        $cursor,
                        $highWater,
                        $chunkSize,
                    ),
                    'high_water' => $highWater,
                    'cursor' => $cursor,
                    'chunk_size' => $chunkSize,
                ];
            }

            return new StreamedResponse(function () use (
                $tempDb,
                $table,
                $primaryKey,
                $primaryKeyColumn,
                $columns,
                $hasTimestamps,
                $nativeDumper,
                $mysqldumpPath,
                $resolver,
                $sanitizeRules,
                $sanitizer,
                $limiter,
                $schema,
                $after,
                $chunkContext,
                $dumpLock,
            ) {
                $meta = json_encode(array_merge([
                    'primary_key' => $primaryKey,
                    'has_timestamps' => $hasTimestamps,
                ], $chunkContext === null ? [] : [
                    'chunked' => true,
                    'high_water' => $chunkContext['high_water'],
                ]));
                echo $meta."\n";
                echo "-- BEGIN SQL DUMP --\n";
                flush();

                try {
                    $chunkResult = null;

                    if ($chunkContext === null) {
                        $tempDb->prepare(
                            $table,
                            $resolver?->buildKeepQuery($table),
                            $primaryKeyColumn,
                            function (int $rowCount): void {
                                echo "-- BACKFILL PREPARED {$rowCount} ROWS --\n";
                                flush();
                            },
                        );

                        if (! empty($sanitizeRules)) {
                            $sanitizer->sanitize($table, $sanitizeRules, $tempDb);
                        }

                        if ($resolver !== null) {
                            $limiter->apply($table, $tempDb, $resolver, $schema);
                        }

                        if ($after && $hasTimestamps) {
                            $qualified = $tempDb->qualifiedTableName($table);
                            DB::statement(
                                "DELETE FROM {$qualified} WHERE `created_at` < ? AND `updated_at` < ?",
                                [$after, $after]
                            );
                        }
                    } else {
                        $tempDb->prepare(
                            $table,
                            $chunkContext['keep_query'],
                            $primaryKeyColumn,
                            null,
                        );

                        if (! empty($sanitizeRules)) {
                            $sanitizer->sanitize($table, $sanitizeRules, $tempDb);
                        }

                        $state = $tempDb->getPreparedChunkState($table, $primaryKeyColumn);
                        $chunkResult = [
                            'next_cursor' => $state['next_cursor'] ?? $chunkContext['cursor'],
                            'complete' => $state['row_count'] < $chunkContext['chunk_size'],
                            'chunk_rows' => $state['row_count'],
                        ];
                    }

                    $dumpArgs = $mysqldumpPath === null
                        ? null
                        : $this->buildMysqldumpArgs($tempDb, $table, $mysqldumpPath);

                    if ($dumpArgs === null) {
                        $nativeDumper->stream(
                            $table,
                            $tempDb,
                            $columns,
                            $primaryKey,
                        );
                    } else {
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
                            echo "\n-- DUMP ERROR: ".$process->getErrorOutput()." --\n";

                            return;
                        }
                    }

                    if ($chunkResult !== null) {
                        echo '-- END BACKFILL CHUNK '.json_encode($chunkResult)." --\n";
                        flush();
                    }
                } catch (\Throwable $e) {
                    echo "\n-- DUMP ERROR: ".$e->getMessage()." --\n";
                } finally {
                    try {
                        $tempDb->cleanup($table);
                    } finally {
                        $dumpLock?->release();
                    }
                }
            }, 200, [
                'Content-Type' => 'application/octet-stream',
                'X-Backfill-Table' => $table,
                'X-Backfill-Format' => 'sqldump',
                'Cache-Control' => 'no-cache',
                'Content-Disposition' => "attachment; filename=\"{$table}.sql\"",
                'X-Accel-Buffering' => 'no',
            ]);
        } catch (\Throwable $e) {
            $dumpLock?->release();

            try {
                $tempDb->cleanup($table);
            } catch (\Throwable $cleanupException) {
                // Ignore cleanup errors during an existing error
            }

            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    protected function numericCursor(mixed $value, string $name): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_scalar($value) || preg_match('/^-?\d+$/', (string) $value) !== 1) {
            throw new RuntimeException("Invalid Backfill {$name}.");
        }

        return (string) $value;
    }

    protected function buildChunkKeepQuery(
        string $baseKeepQuery,
        string $primaryKey,
        ?string $cursor,
        string $highWater,
        int $chunkSize,
    ): string {
        $query = "SELECT `{$primaryKey}` FROM ({$baseKeepQuery}) AS `_backfill_chunk`"
            ." WHERE `_backfill_chunk`.`{$primaryKey}` <= {$highWater}";

        if ($cursor !== null) {
            $query .= " AND `_backfill_chunk`.`{$primaryKey}` > {$cursor}";
        }

        return $query
            ." ORDER BY `_backfill_chunk`.`{$primaryKey}` ASC"
            ." LIMIT {$chunkSize}";
    }

    /**
     * Build the mysqldump command arguments for a single table in the temp space.
     */
    protected function buildMysqldumpArgs(
        TempDatabaseService $tempDb,
        string $table,
        string $mysqldumpPath,
    ): array {
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
            $tempTableName = '_backfill_'.$table;

            return array_filter([
                $mysqldumpPath,
                '--host='.$host,
                '--port='.$port,
                '--user='.$username,
                $password ? '--password='.$password : null,
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
            $mysqldumpPath,
            '--host='.$host,
            '--port='.$port,
            '--user='.$username,
            $password ? '--password='.$password : null,
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
