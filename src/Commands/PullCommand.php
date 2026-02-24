<?php

namespace Elliptic\Backfill\Commands;

use Elliptic\Backfill\Services\ImportService;
use Elliptic\Backfill\Services\SyncClient;
use Elliptic\Backfill\Services\SyncState;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class PullCommand extends Command
{
    protected $signature = 'backfill:pull
        {--full : Force a full sync, ignoring last pull timestamp}
        {--tables= : Comma-separated list of specific tables to sync}
        {--dry-run : Show what would be synced without making changes}
        {--force : Accept all questions and warnings automatically}
        {--fresh : Download fresh data even if a recent local copy exists}';

    protected $aliases = ['backfill'];

    protected $description = 'Pull a sanitized copy of the production database to this environment';

    public function handle(SyncClient $client, ImportService $importer, SyncState $state): int
    {
        $allowed = config('backfill.client.allowed_environments', ['local', 'staging']);
        if (! app()->environment($allowed)) {
            $this->error('🚫 backfill:pull is only allowed in these environments: '.implode(', ', $allowed));
            $this->error('   Current environment: '.app()->environment());

            return self::FAILURE;
        }

        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if ($driver !== 'mysql' && $driver !== 'mariadb' && ! app()->runningUnitTests()) {
            $this->error('🚫 Backfill requires a MySQL or MariaDB connection. Current driver: '.$driver);

            return self::FAILURE;
        }

        $isDelta = ! $this->option('full');
        $isDryRun = $this->option('dry-run');
        $isForce = $this->option('force');

        // Clean up old downloads before starting
        $this->cleanupOldDownloads();

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

                if (! $isForce && ! $this->confirm('Continue anyway?', false)) {
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

        // Store the server time from the manifest to use as the sync checkpoint
        $serverTime = null;

        // Fetch manifest
        $this->info('📋 Fetching manifest from server...');

        try {
            $manifest = $client->getManifest($lastSync);
        } catch (\Throwable $e) {
            $this->error("Failed to fetch manifest: {$e->getMessage()}");

            return self::FAILURE;
        }

        $tableOrder = $manifest['table_order'] ?? [];
        $tableInfo = $manifest['tables'] ?? [];
        $serverTime = $manifest['server_time'] ?? null;

        // Filter tables if --tables option is provided
        $filterTables = $this->option('tables');
        if ($filterTables) {
            $requested = array_map('trim', explode(',', $filterTables));
            $tableOrder = array_values(array_filter($tableOrder, fn ($t) => in_array($t, $requested)));

            $missing = array_diff($requested, $tableOrder);
            if (! empty($missing)) {
                $this->warn('Tables not found on server: '.implode(', ', $missing));
            }
        }

        // Exclude internal tables
        $tableOrder = array_values(array_filter($tableOrder, fn ($t) => $t !== 'BACKFILL_logs'));

        $totalTables = count($tableOrder);
        $totalRows = array_sum(array_map(function ($t) use ($tableInfo, $isDelta) {
            $info = $tableInfo[$t] ?? [];
            $hasTimestamps = $info['has_timestamps'] ?? false;

            // In delta mode, use delta_count for tables with timestamps, full row_count otherwise
            if ($isDelta && $hasTimestamps) {
                return $info['delta_count'] ?? 0;
            }

            return $info['row_count'] ?? 0;
        }, $tableOrder));

        $this->info("   Tables: {$totalTables}");
        $this->info('   Estimated rows to sync: '.number_format($totalRows));

        if ($isDryRun) {
            $this->newLine();
            $this->info('📊 Dry run — tables that would be synced:');
            $this->newLine();

            $tableData = [];
            foreach ($tableOrder as $table) {
                $info = $tableInfo[$table] ?? [];
                $hasTimestamps = $info['has_timestamps'] ?? false;
                $tableRows = ($isDelta && $hasTimestamps) ? ($info['delta_count'] ?? 0) : ($info['row_count'] ?? 0);
                $note = ($isDelta && ! $hasTimestamps) ? 'full (no timestamps)' : '';
                $tableData[] = [
                    $table,
                    number_format($tableRows),
                    ($info['has_sanitization'] ?? false) ? '✓' : '',
                    ($info['has_limit'] ?? false) ? '✓' : '',
                    $note,
                ];
            }

            $this->table(['Table', 'Rows', 'Sanitized', 'Limited', 'Note'], $tableData);

            return self::SUCCESS;
        }

        // Confirm with the user
        if (! $isDelta && ! $isForce && ! $this->confirm("Proceed with {$mode} sync of {$totalTables} tables?", true)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        // ──────────────────────────────────────────────────
        // Phase 1: Download all dumps
        // ──────────────────────────────────────────────────

        $isCached = false;
        $tempDir = $this->resolveDownloadDirectory($tableOrder, $tableInfo, $isCached);

        if ($isCached) {
            $this->newLine();
            $this->info('📥 Phase 1: Using locally cached tables...');
        } else {
            $this->newLine();
            $this->info('📥 Phase 1: Downloading all tables...');
            $this->newLine();

            $downloadBar = $this->output->createProgressBar($totalTables);
            $downloadBar->setFormat(' %current%/%max% [%bar%] %message%');
            $downloadBar->start();

            foreach ($tableOrder as $table) {
                $info = $tableInfo[$table] ?? [];
                $isDeltaTable = $isDelta && ($info['has_timestamps'] ?? false);
                $after = $isDeltaTable ? $lastSync : null;

                if ($isDeltaTable && isset($info['delta_count']) && $info['delta_count'] === 0) {
                    $downloadBar->advance();

                    // we can't emit info text inside progress bar easily without breaking it,
                    // so we just advance and continue.
                    continue;
                }

                $downloadBar->setMessage("Downloading {$table}...");
                $downloadBar->display();

                try {
                    $client->downloadTableDump($table, $tempDir, $after);
                } catch (\Throwable $e) {
                    $this->newLine();
                    $this->error("Error downloading {$table}: {$e->getMessage()}");
                }

                $downloadBar->advance();
            }

            $downloadBar->setMessage('Downloads complete!');
            $downloadBar->finish();
            $this->newLine(2);

            // Save a timestamp marker so we can detect this download later
            file_put_contents($tempDir.DIRECTORY_SEPARATOR.'.backfill-meta.json', json_encode([
                'downloaded_at' => now()->toIso8601String(),
                'mode' => $mode,
                'table_order' => $tableOrder,
                'table_info' => $tableInfo,
            ], JSON_PRETTY_PRINT));
        }

        // ──────────────────────────────────────────────────
        // Phase 2: Schema comparison
        // ──────────────────────────────────────────────────

        $schemaDiffs = $this->compareSchemas($tableOrder, $tableInfo);

        if (! empty($schemaDiffs)) {
            $this->newLine();
            $this->components->warn('Schema differences detected between the server and local database:');
            $this->newLine();

            $this->table(
                ['Table', 'Issue'],
                array_map(fn ($diff) => [$diff['table'], $diff['issue']], $schemaDiffs)
            );

            $this->newLine();
            $this->line('  <fg=yellow>These differences may cause import errors.</> Review and resolve them,');
            $this->line('  or run <fg=white>php artisan migrate</> first.');
            $this->newLine();

            if (! $isForce && ! $this->confirm('Continue importing despite schema differences?', false)) {
                $this->info('Import cancelled. Downloaded data is preserved at:');
                $this->line("  <fg=white>{$tempDir}</>");

                return self::SUCCESS;
            }
        }

        // ──────────────────────────────────────────────────
        // Phase 3: Import all dumps
        // ──────────────────────────────────────────────────

        $this->newLine();
        $this->info('📦 Phase 2: Importing tables...');
        $this->newLine();

        // Record start
        $startedAt = Carbon::now();
        $syncId = $state->recordStart($mode, config('backfill.client.source_url', ''), $serverTime);

        $totalRowsSynced = 0;
        $syncedTables = [];

        // Determine which tables actually have downloaded dump files
        $importableTables = array_filter($tableOrder, function ($table) use ($tempDir) {
            return file_exists($tempDir.DIRECTORY_SEPARATOR."{$table}.sql");
        });

        $importBar = $this->output->createProgressBar(count($importableTables));
        $importBar->setFormat(' %current%/%max% [%bar%] %message%');
        $importBar->start();

        foreach ($importableTables as $table) {
            $info = $tableInfo[$table] ?? [];
            $dumpPath = $tempDir.DIRECTORY_SEPARATOR."{$table}.sql";

            $importBar->setMessage("Importing {$table}...");
            $importBar->display();

            try {
                $tableIsDelta = $isDelta && ($info['has_timestamps'] ?? false);
                $rowCount = $importer->importSqlDump($table, $dumpPath, $tableIsDelta);

                if ($tableIsDelta && isset($info['delta_count'])) {
                    $rowCount = $info['delta_count'];
                }

                $totalRowsSynced += $rowCount;
                $syncedTables[$table] = $rowCount;
            } catch (\Throwable $e) {
                $this->newLine();
                $this->error("Error importing {$table}: {$e->getMessage()}");
                $syncedTables[$table] = 'error: '.$e->getMessage();
            }

            $importBar->advance();
        }

        $importBar->setMessage('Done!');
        $importBar->finish();

        // Add skipped delta tables to synced tables as 0 rows
        foreach ($tableOrder as $table) {
            if (! isset($syncedTables[$table])) {
                $info = $tableInfo[$table] ?? [];
                if ($isDelta && isset($info['delta_count']) && $info['delta_count'] === 0) {
                    $syncedTables[$table] = 0;
                }
            }
        }

        $this->newLine(2);

        // Record completion
        $state->recordComplete($syncId, $syncedTables, $totalRowsSynced);

        $this->info('✅ Sync complete!');
        $this->info("   Mode: {$mode}");
        $this->info('   Tables: '.count(array_filter($syncedTables, fn ($v) => is_int($v))));
        $this->info('   Rows synced: '.number_format($totalRowsSynced));
        $this->info('   Duration: '.$startedAt->diffForHumans(now(), true));

        // Per-table summary
        $this->newLine();
        $summaryData = [];
        foreach ($syncedTables as $table => $result) {
            $info = $tableInfo[$table] ?? [];
            $hasTimestamps = $info['has_timestamps'] ?? false;

            if (is_string($result)) {
                $note = $result; // error message
                $rows = '—';
            } else {
                $rows = number_format($result);
                if ($isDelta && ! $hasTimestamps) {
                    $note = 'full sync (no timestamps)';
                } elseif ($isDelta && $result === 0) {
                    $note = 'up to date';
                } else {
                    $note = '';
                }
            }

            $summaryData[] = [$table, $rows, $note];
        }
        $this->table(['Table', 'Rows', 'Note'], $summaryData);

        // Dispatch event so apps can run post-sync hooks (cache clear, scout index, etc.)
        \Elliptic\Backfill\Events\SyncCompleted::dispatch(
            $mode,
            $syncedTables,
            $totalRowsSynced
        );

        return self::SUCCESS;
    }

    /**
     * Resolve the directory to use for the download.
     */
    protected function resolveDownloadDirectory(array $tableOrder, array $tableInfo, bool &$isCached): string
    {
        $existing = $this->findRecentDownloadDir();

        if ($existing) {
            if ($this->option('fresh')) {
                File::deleteDirectory($existing);
            } else {
                $metaFile = $existing.DIRECTORY_SEPARATOR.'.backfill-meta.json';
                $meta = json_decode(file_get_contents($metaFile), true);
                $downloadedAt = Carbon::parse($meta['downloaded_at']);
                $age = $downloadedAt->diffForHumans(now(), true);

                $dumpFileCount = count(glob($existing.DIRECTORY_SEPARATOR.'*.sql'));

                $this->newLine();
                $this->components->info("Found a recent local cache from {$age} ago with {$dumpFileCount} table dumps. Using local copy.");

                $isCached = true;

                return $existing;
            }
        }

        $newDir = storage_path('app/backfill-'.time());
        File::ensureDirectoryExists($newDir);
        $isCached = false;

        return $newDir;
    }

    /**
     * Find the most recent backfill download directory that is within the configured limit.
     */
    protected function findRecentDownloadDir(): ?string
    {
        $appDir = storage_path('app');

        if (! is_dir($appDir)) {
            return null;
        }

        $dirs = glob($appDir.'/backfill-*', GLOB_ONLYDIR);

        if (empty($dirs)) {
            return null;
        }

        rsort($dirs);
        $cacheHours = config('backfill.client.local_cache_hours', 1);

        foreach ($dirs as $dir) {
            $metaFile = $dir.DIRECTORY_SEPARATOR.'.backfill-meta.json';

            if (! file_exists($metaFile)) {
                continue;
            }

            $meta = json_decode(file_get_contents($metaFile), true);
            $downloadedAt = Carbon::parse($meta['downloaded_at'] ?? '2000-01-01');

            if ($downloadedAt->diffInHours(now()) < $cacheHours) {
                return $dir;
            }
        }

        return null;
    }

    /**
     * Clean up very old backfill download directories on init.
     */
    protected function cleanupOldDownloads(): void
    {
        $appDir = storage_path('app');

        if (! is_dir($appDir)) {
            return;
        }

        $dirs = glob($appDir . '/backfill-*', GLOB_ONLYDIR);
        $cacheHours = config('backfill.client.local_cache_hours', 1);

        foreach ($dirs as $dir) {
            $metaFile = $dir . DIRECTORY_SEPARATOR . '.backfill-meta.json';

            if (file_exists($metaFile)) {
                $meta = json_decode(file_get_contents($metaFile), true);
                $downloadedAt = Carbon::parse($meta['downloaded_at'] ?? '2000-01-01');

                if ($downloadedAt->diffInHours(now()) >= $cacheHours) {
                    File::deleteDirectory($dir);
                }
            } else {
                $dirTime = filemtime($dir);
                if ((time() - $dirTime) > ($cacheHours * 3600)) {
                    File::deleteDirectory($dir);
                }
            }
        }
    }

    /**
     * Compare the remote schema (from manifest) with the local database schema.
     * Returns an array of differences: [['table' => ..., 'issue' => ...], ...]
     */
    protected function compareSchemas(array $tableOrder, array $tableInfo): array
    {
        $diffs = [];

        foreach ($tableOrder as $table) {
            $remoteColumns = $tableInfo[$table]['columns'] ?? [];

            // Normalize remote column names
            $remoteColumnNames = array_map(function ($col) {
                return is_array($col) ? ($col['name'] ?? $col) : $col;
            }, $remoteColumns);

            // Check if the table exists locally
            if (! Schema::hasTable($table)) {
                $diffs[] = [
                    'table' => $table,
                    'issue' => 'Table does not exist locally',
                ];

                continue;
            }

            $localColumns = Schema::getColumnListing($table);

            $missingLocally = array_diff($remoteColumnNames, $localColumns);
            $extraLocally = array_diff($localColumns, $remoteColumnNames);

            if (! empty($missingLocally)) {
                $diffs[] = [
                    'table' => $table,
                    'issue' => 'Columns on server but missing locally: '.implode(', ', $missingLocally),
                ];
            }

            if (! empty($extraLocally)) {
                $diffs[] = [
                    'table' => $table,
                    'issue' => 'Columns local but missing on server: '.implode(', ', $extraLocally),
                ];
            }
        }

        return $diffs;
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
