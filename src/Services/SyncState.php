<?php

namespace Elliptic\Backfill\Services;

use Illuminate\Support\Facades\File;

class SyncState
{
    protected string $path;

    protected array $state;

    public function __construct()
    {
        $this->path = storage_path('backfill-state.json');
        $this->state = $this->load();
    }

    /**
     * Get the timestamp of the last completed sync.
     */
    public function lastSyncTimestamp(): ?string
    {
        return $this->state['last_completed_at'] ?? null;
    }

    /**
     * Get the full history of sync operations.
     */
    public function history(): array
    {
        return $this->state['history'] ?? [];
    }

    /**
     * Get the most recent N entries from history.
     */
    public function recentHistory(int $limit = 10): array
    {
        $history = $this->history();

        return array_slice($history, -$limit);
    }

    /**
     * Record the start of a sync operation.
     * Returns the entry ID for later updates.
     */
    public function recordStart(string $mode, string $sourceUrl): int
    {
        $id = ($this->state['next_id'] ?? 1);
        $this->state['next_id'] = $id + 1;

        $entry = [
            'id' => $id,
            'mode' => $mode,
            'source_url' => $sourceUrl,
            'started_at' => now()->toIso8601String(),
            'completed_at' => null,
            'tables_synced' => [],
            'rows_synced' => 0,
        ];

        $this->state['history'][] = $entry;
        $this->save();

        return $id;
    }

    /**
     * Record the completion of a sync operation.
     */
    public function recordComplete(int $id, array $tablesSynced, int $rowsSynced): void
    {
        $completedAt = now()->toIso8601String();

        foreach ($this->state['history'] as &$entry) {
            if ($entry['id'] === $id) {
                $entry['completed_at'] = $completedAt;
                $entry['tables_synced'] = $tablesSynced;
                $entry['rows_synced'] = $rowsSynced;
                break;
            }
        }

        $this->state['last_completed_at'] = $completedAt;

        // Keep only the last 50 entries to avoid unbounded growth
        if (count($this->state['history']) > 50) {
            $this->state['history'] = array_slice($this->state['history'], -50);
        }

        $this->save();
    }

    /**
     * Load state from the JSON file.
     */
    protected function load(): array
    {
        if (! File::exists($this->path)) {
            return [
                'next_id' => 1,
                'last_completed_at' => null,
                'history' => [],
            ];
        }

        $json = File::get($this->path);
        $data = json_decode($json, true);

        return is_array($data) ? $data : [
            'next_id' => 1,
            'last_completed_at' => null,
            'history' => [],
        ];
    }

    /**
     * Save state to the JSON file.
     */
    protected function save(): void
    {
        $dir = dirname($this->path);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::put($this->path, json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
