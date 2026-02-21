<?php

namespace Elliptic\Backfill\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SyncCompleted
{
    use Dispatchable, SerializesModels;

    /**
     * @param string $mode 'full' or 'delta'
     * @param array $syncedTables Array of table names => rows synced
     * @param int $totalRowsSynced Total number of rows imported
     */
    public function __construct(
        public readonly string $mode,
        public readonly array $syncedTables,
        public readonly int $totalRowsSynced,
    ) {}
}
