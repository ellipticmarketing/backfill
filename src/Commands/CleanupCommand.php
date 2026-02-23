<?php

namespace Elliptic\Backfill\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupCommand extends Command
{
    protected $signature = 'backfill:cleanup
        {--force : Skip confirmation prompt}
        {--max-age=60 : Only drop temp databases older than this many minutes}';

    protected $description = 'Drop any orphaned temporary databases left behind by failed sync operations';

    public function handle(): int
    {
        $maxAgeMinutes = (int) $this->option('max-age');
        $cutoff = now()->subMinutes($maxAgeMinutes)->timestamp;

        $stale = $this->findStaleTempDatabases($cutoff);

        if (empty($stale)) {
            $this->info('✅ No orphaned temp databases found.');

            return self::SUCCESS;
        }

        $this->warn('Found '.count($stale).' orphaned temp database(s):');
        foreach ($stale as $db) {
            $age = now()->diffForHumans(\Carbon\Carbon::createFromTimestamp($db['timestamp']), true);
            $this->line("   • {$db['name']} (created ~{$age} ago)");
        }

        if (! $this->option('force') && ! $this->confirm('Drop all orphaned temp databases?')) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $connection = $this->resolveConnection();

        foreach ($stale as $db) {
            try {
                DB::connection($connection)->statement("DROP DATABASE IF EXISTS `{$db['name']}`");
                $this->info("   Dropped {$db['name']}");
            } catch (\Throwable $e) {
                $this->error("   Failed to drop {$db['name']}: {$e->getMessage()}");
            }
        }

        // Also clean up any orphaned temp tables in the current database
        $this->cleanupTempTables($connection);

        $this->info('✅ Cleanup complete.');

        return self::SUCCESS;
    }

    /**
     * Find all databases matching the _backfill_temp_* pattern that are older than the cutoff.
     */
    protected function findStaleTempDatabases(int $cutoffTimestamp): array
    {
        $connection = $this->resolveConnection();

        $databases = DB::connection($connection)->select(
            "SHOW DATABASES LIKE '_backfill_temp_%'"
        );

        $stale = [];
        foreach ($databases as $row) {
            $name = array_values((array) $row)[0];

            // Extract timestamp from the name: _backfill_temp_{timestamp}_{random}
            if (preg_match('/_backfill_temp_(\d+)_\d+/', $name, $matches)) {
                $timestamp = (int) $matches[1];

                // Only include if older than the cutoff (avoids killing an active sync)
                if ($timestamp <= $cutoffTimestamp) {
                    $stale[] = [
                        'name' => $name,
                        'timestamp' => $timestamp,
                    ];
                }
            }
        }

        return $stale;
    }

    /**
     * Clean up any orphaned _backfill_* temp tables in the current database.
     */
    protected function cleanupTempTables(string $connection): void
    {
        $database = config('database.connections.'.config('database.default').'.database');

        $tables = DB::connection($connection)->select(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ?',
            [$database, '_backfill_%']
        );

        foreach ($tables as $row) {
            $tableName = $row->TABLE_NAME;

            try {
                DB::connection($connection)->statement("DROP TABLE IF EXISTS `{$tableName}`");
                $this->info("   Dropped temp table {$tableName}");
            } catch (\Throwable $e) {
                $this->error("   Failed to drop {$tableName}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Use alternate credentials if configured, otherwise default.
     */
    protected function resolveConnection(): string
    {
        $tempUsername = config('backfill.server.temp_username');

        if ($tempUsername) {
            $defaultConnection = config('database.default');
            $baseConfig = config("database.connections.{$defaultConnection}");

            config([
                'database.connections.BACKFILL_temp' => array_merge($baseConfig, [
                    'username' => $tempUsername,
                    'password' => config('backfill.server.temp_password', ''),
                ]),
            ]);

            return 'BACKFILL_temp';
        }

        return config('database.default');
    }
}
