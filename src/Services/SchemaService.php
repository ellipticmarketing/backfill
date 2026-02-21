<?php

namespace Elliptic\Backfill\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SchemaService
{
    /**
     * Get all table names in the database, excluding the given list.
     */
    public function getTables(array $exclude = []): array
    {
        $tables = Schema::getTableListing();

        return array_values(array_diff($tables, $exclude));
    }

    /**
     * Get column names for a table.
     */
    public function getColumns(string $table): array
    {
        return Schema::getColumnListing($table);
    }

    /**
     * Get the primary key column(s) for a table.
     */
    public function getPrimaryKey(string $table): array
    {
        $indexes = Schema::getIndexes($table);

        foreach ($indexes as $index) {
            if ($index['primary'] ?? false) {
                return $index['columns'];
            }
        }

        // Fallback: check if 'id' column exists
        if (in_array('id', $this->getColumns($table))) {
            return ['id'];
        }

        return [];
    }

    /**
     * Check if a table has created_at and updated_at columns.
     */
    public function hasTimestamps(string $table): bool
    {
        $columns = $this->getColumns($table);

        return in_array('created_at', $columns) && in_array('updated_at', $columns);
    }

    /**
     * Get approximate row count for a table (fast, uses information_schema).
     */
    public function getRowCount(string $table): int
    {
        $database = config('database.connections.' . config('database.default') . '.database');

        $result = DB::selectOne(
            'SELECT TABLE_ROWS as count FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $table]
        );

        return (int) ($result->count ?? 0);
    }

    /**
     * Get foreign key relationships for all tables.
     * Returns array of ['table' => string, 'column' => string, 'referenced_table' => string, 'referenced_column' => string]
     */
    public function getForeignKeys(array $tables): array
    {
        $database = config('database.connections.' . config('database.default') . '.database');

        $fks = DB::select(
            'SELECT TABLE_NAME as `table`, COLUMN_NAME as `column`,
                    REFERENCED_TABLE_NAME as referenced_table, REFERENCED_COLUMN_NAME as referenced_column
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$database]
        );

        return array_filter(
            array_map(fn ($fk) => (array) $fk, $fks),
            fn ($fk) => in_array($fk['table'], $tables) && in_array($fk['referenced_table'], $tables)
        );
    }

    /**
     * Sort tables in topological order so parent tables come before children.
     * This ensures foreign key constraints are satisfied during import.
     */
    public function topologicalSort(array $tables): array
    {
        $fks = $this->getForeignKeys($tables);

        // Build adjacency list: parent → [children]
        // A table that references another must come AFTER the referenced table
        $dependencies = [];
        foreach ($tables as $table) {
            $dependencies[$table] = [];
        }

        foreach ($fks as $fk) {
            // $fk['table'] depends on $fk['referenced_table']
            if ($fk['table'] !== $fk['referenced_table']) { // skip self-references
                $dependencies[$fk['table']][] = $fk['referenced_table'];
            }
        }

        $sorted = [];
        $visited = [];
        $visiting = [];

        $visit = function (string $table) use (&$visit, &$sorted, &$visited, &$visiting, $dependencies) {
            if (isset($visited[$table])) {
                return;
            }

            // Detect circular dependencies — just break the cycle
            if (isset($visiting[$table])) {
                return;
            }

            $visiting[$table] = true;

            foreach ($dependencies[$table] ?? [] as $dep) {
                $visit($dep);
            }

            unset($visiting[$table]);
            $visited[$table] = true;
            $sorted[] = $table;
        };

        foreach ($tables as $table) {
            $visit($table);
        }

        return $sorted;
    }
}
