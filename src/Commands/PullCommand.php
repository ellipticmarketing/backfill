<?php

namespace Elliptic\Backfill\Commands;

use Elliptic\Backfill\Services\ImportService;
use Elliptic\Backfill\Services\SyncClient;
use Elliptic\Backfill\Services\SyncState;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

class PullCommand extends Command
{
    protected $signature = 'backfill:pull
        {--full : Force a full sync, ignoring last pull timestamp}
        {--tables= : Comma-separated list of specific tables to sync}
        {--dry-run : Show what would be synced without making changes}';

    protected $description = 'Pull a sanitized copy of the production database to this environment';

    public function handle(SyncClient $client, ImportService $importer, SyncState $state): int
    {
        $allowed = config('backfill.client.allowed_environments', ['local', 'staging']);
        if (! app()->environment($allowed)) {
            $this->error("🚫 backfill:pull is only allowed in these environments: " . implode(', ', $allowed));
            $this->error("   Current environment: " . app()->environment());

            return self::FAILURE;
        }

        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if ($driver !== 'mysql' && $driver !== 'mariadb' && ! app()->runningUnitTests()) {
            $this->error('🚫 Backfill requires a MySQL or MariaDB connection. Current driver: ' . $driver);
            return self::FAILURE;
        }

        $isDelta = ! $this->option('full');
        $isDryRun = $this->option('dry-run');

        // Pre-flight checks (skip for dry-run since no import happens)
        if (! $isDryRun) {
            $issues = $this->runPreflightChecks();
            if (! empty($issues)) {
                $this->newLine();
                $this->components->warn('Pre-flight checks found issues:');
                foreach ($issues as $issue) {
                    $this->line("  <fg=yellow>⚠</> {$issue}");
                }
                $this->newLine();

                if (! $this->confirm('Continue anyway?', false)) {
                    return self::FAILURE;
                }
            }
        }

        // Determine last sync timestamp for delta mode
        $lastSync = null;
        if ($isDelta) {
            $lastSync = $state->lastSyncTimestamp();
            if (! $lastSync) {
                $this->warn('No previous sync found. Performing a full sync.');
                $isDelta = false;
            }
        }

        $mode = $isDelta ? 'delta' : 'full';
        $this->info("🔄 Starting {$mode} database sync...");

        if ($isDelta) {
            $this->info("   Last sync: {$lastSync}");
        }

        // Fetch manifest
        $this->info('📋 Fetching manifest from server...');

        try {
            $manifest = $client->getManifest();
        } catch (\Throwable $e) {
            $this->error("Failed to fetch manifest: {$e->getMessage()}");

            return self::FAILURE;
        }

        $tableOrder = $manifest['table_order'] ?? [];
        $tableInfo = $manifest['tables'] ?? [];

        // Filter tables if --tables option is provided
        $filterTables = $this->option('tables');
        if ($filterTables) {
            $requested = array_map('trim', explode(',', $filterTables));
            $tableOrder = array_values(array_filter($tableOrder, fn ($t) => in_array($t, $requested)));

            $missing = array_diff($requested, $tableOrder);
            if (! empty($missing)) {
                $this->warn('Tables not found on server: ' . implode(', ', $missing));
            }
        }

        // Exclude internal tables
        $tableOrder = array_values(array_filter($tableOrder, fn ($t) => $t !== 'BACKFILL_logs'));

        $totalTables = count($tableOrder);
        $totalRows = array_sum(array_map(fn ($t) => $tableInfo[$t]['row_count'] ?? 0, $tableOrder));

        $this->info("   Tables: {$totalTables}");
        $this->info("   Estimated rows: " . number_format($totalRows));

        if ($isDryRun) {
            $this->newLine();
            $this->info('📊 Dry run — tables that would be synced:');
            $this->newLine();

            $tableData = [];
            foreach ($tableOrder as $table) {
                $info = $tableInfo[$table] ?? [];
                $tableData[] = [
                    $table,
                    number_format($info['row_count'] ?? 0),
                    ($info['has_sanitization'] ?? false) ? '✓' : '',
                    ($info['has_limit'] ?? false) ? '✓' : '',
                    ($info['has_timestamps'] ?? false) ? '✓' : '',
                ];
            }

            $this->table(['Table', 'Rows', 'Sanitized', 'Limited', 'Timestamps'], $tableData);

            return self::SUCCESS;
        }

        // Confirm with the user
        if (! $this->confirm("Proceed with {$mode} sync of {$totalTables} tables?", true)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        // Create a temp directory for SQL dump files
        $tempDir = storage_path('app/backfill-' . time());
        File::ensureDirectoryExists($tempDir);

        // Record start
        $startedAt = Carbon::now();
        $syncId = $state->recordStart($mode, config('backfill.client.source_url', ''));

        $totalRowsSynced = 0;
        $syncedTables = [];
        $progressBar = $this->output->createProgressBar($totalTables);
        $progressBar->setFormat(' %current%/%max% [%bar%] %message%');
        $progressBar->start();

        foreach ($tableOrder as $table) {
            $info = $tableInfo[$table] ?? [];
            $progressBar->setMessage("Downloading {$table}...");
            $progressBar->display();

            try {
                // Download the SQL dump from the server
                $after = ($isDelta && ($info['has_timestamps'] ?? false)) ? $lastSync : null;
                $result = $client->downloadTableDump($table, $tempDir, $after);

                $dumpPath = $result['path'];

                // Determine if this specific table should use delta mode
                $tableIsDelta = $isDelta && ($info['has_timestamps'] ?? false);

                $progressBar->setMessage("Importing {$table}...");
                $progressBar->display();

                // Import the SQL dump
                $rowCount = $importer->importSqlDump($table, $dumpPath, $tableIsDelta);

                $totalRowsSynced += $rowCount;
                $syncedTables[$table] = $rowCount;

                // Clean up the dump file immediately
                @unlink($dumpPath);
            } catch (\Throwable $e) {
                $this->newLine();
                $this->error("Error syncing {$table}: {$e->getMessage()}");
                $syncedTables[$table] = 'error: ' . $e->getMessage();
            }

            $progressBar->advance();
        }

        $progressBar->setMessage('Done!');
        $progressBar->finish();
        $this->newLine(2);

        // Clean up temp directory
        File::deleteDirectory($tempDir);

        // Record completion
        $state->recordComplete($syncId, $syncedTables, $totalRowsSynced);

        $this->info("✅ Sync complete!");
        $this->info("   Mode: {$mode}");
        $this->info("   Tables: " . count(array_filter($syncedTables, fn ($v) => is_int($v))));
        $this->info("   Rows synced: " . number_format($totalRowsSynced));
        $this->info("   Duration: " . $startedAt->diffForHumans(now(), true));

        // Dispatch event so apps can run post-sync hooks (cache clear, scout index, etc.)
        \Elliptic\Backfill\Events\SyncCompleted::dispatch(
            $mode, 
            $syncedTables, 
            $totalRowsSynced
        );

        return self::SUCCESS;
    }

    /**
     * Run pre-flight checks before starting the sync.
     * Returns an array of warning messages (empty = all clear).
     */
    protected function runPreflightChecks(): array
    {
        $issues = [];

        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        // Check if database is MySQL/MariaDB
        if ($driver !== 'mysql' && $driver !== 'mariadb' && ! app()->runningUnitTests()) {
            $issues[] = "Backfill requires a <fg=white>MySQL/MariaDB</> connection. Current driver: <fg=white>{$driver}</>.";
        }

        // Check if mysql CLI is available (needed for import)

        if ($driver === 'mysql') {
            $mysqlCheck = @exec('mysql --version 2>&1', $output, $exitCode);
            if ($exitCode !== 0) {
                $issues[] = 'The <fg=white>mysql</> CLI tool was not found. Import will fall back to PHP (slower).';
                $issues[] = 'Install it via: <fg=white>apt install mysql-client</> or <fg=white>brew install mysql-client</>';
            }
        }

        // Check source URL is configured
        if (empty(config('backfill.client.source_url'))) {
            $issues[] = 'BACKFILL_SOURCE_URL is not set. Run <fg=white>php artisan backfill:install</> to configure.';
        }

        // Check auth token is configured
        if (empty(config('backfill.auth_token'))) {
            $issues[] = 'BACKFILL_TOKEN is not set. Run <fg=white>php artisan backfill:install</> to generate one.';
        }

        // Check disk space (warn if less than 1GB free in storage/)
        $freeBytes = @disk_free_space(storage_path());
        if ($freeBytes !== false && $freeBytes < 1_073_741_824) {
            $freeMB = round($freeBytes / 1_048_576);
            $issues[] = "Low disk space: only {$freeMB} MB free in storage/. Large dumps may fail.";
        }

        return $issues;
    }
}
