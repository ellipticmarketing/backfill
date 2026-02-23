<?php

namespace Elliptic\Backfill\Commands;

use Elliptic\Backfill\Services\SyncState;
use Illuminate\Console\Command;

class StatusCommand extends Command
{
    protected $signature = 'backfill:status';

    protected $description = 'Show the status and history of database sync operations';

    public function handle(SyncState $state): int
    {
        $history = $state->recentHistory(10);

        if (empty($history)) {
            $this->info('No sync history found. Run `php artisan backfill:pull` to sync.');

            return self::SUCCESS;
        }

        $this->info('📊 Database Sync History (last 10)');
        $this->newLine();

        $tableData = [];
        foreach ($history as $entry) {
            $tables = $entry['tables_synced'] ?? [];
            $successCount = count(array_filter($tables, fn ($v) => is_int($v)));
            $errorCount = count(array_filter($tables, fn ($v) => is_string($v)));

            $status = $entry['completed_at'] ? '✅ Complete' : '⏳ In Progress';
            if ($errorCount > 0) {
                $status = "⚠️ {$successCount} ok / {$errorCount} errors";
            }

            $duration = '-';
            if ($entry['completed_at'] && $entry['started_at']) {
                $started = \Carbon\Carbon::parse($entry['started_at']);
                $completed = \Carbon\Carbon::parse($entry['completed_at']);
                $duration = $started->diffForHumans($completed, true);
            }

            $startedFormatted = $entry['started_at']
                ? \Carbon\Carbon::parse($entry['started_at'])->format('Y-m-d H:i:s')
                : '-';

            $tableData[] = [
                $entry['id'] ?? '-',
                strtoupper($entry['mode'] ?? '-'),
                $status,
                number_format($entry['rows_synced'] ?? 0),
                $successCount.' tables',
                $duration,
                $startedFormatted,
            ];
        }

        $this->table(
            ['ID', 'Mode', 'Status', 'Rows', 'Tables', 'Duration', 'Started'],
            $tableData
        );

        // Show last sync info
        $lastSync = $state->lastSyncTimestamp();
        if ($lastSync) {
            $this->newLine();
            $this->info("Last successful sync: {$lastSync}");
            $this->info('A delta sync will pull data created/updated after this timestamp.');
        }

        return self::SUCCESS;
    }
}
