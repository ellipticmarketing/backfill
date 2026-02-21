<?php

namespace Elliptic\Backfill\Services;

use Illuminate\Support\Facades\DB;

class RowLimiterService
{
    /**
     * Apply row limit to a table in the temp space.
     * Keeps only the top N rows and deletes the rest, respecting FK dependencies.
     */
    public function apply(
        string $table,
        array $limitConfig,
        TempDatabaseService $tempDb,
        SchemaService $schema
    ): void {
        $maxRows = $limitConfig['max_rows'] ?? null;
        $keepDays = $limitConfig['keep_days'] ?? null;
        $orderBy = $limitConfig['order_by'] ?? null;
        $direction = strtoupper($limitConfig['direction'] ?? 'DESC');

        if (! in_array($direction, ['ASC', 'DESC'])) {
            $direction = 'DESC';
        }

        // Determine the ordering/filtering column
        if (! $orderBy) {
            $pk = $schema->getPrimaryKey($table);
            $orderBy = $pk[0] ?? 'id';
        }

        $qualifiedTable = $tempDb->qualifiedTableName($table);
        $pk = $schema->getPrimaryKey($table);
        $pkColumn = $pk[0] ?? 'id';

        $query = DB::table(DB::raw($qualifiedTable));

        if ($keepDays !== null) {
            $cutoff = now()->subDays($keepDays);
            $query->where($orderBy, '>=', $cutoff);
        }

        if ($maxRows !== null) {
            $query->orderBy($orderBy, $direction)->limit($maxRows);
        }

        // Find IDs of rows to KEEP
        $keepIds = $query->pluck($pkColumn)->toArray();

        // If no rows to keep, truncate
        if (empty($keepIds)) {
            DB::statement("DELETE FROM {$qualifiedTable}");

            return;
        }

        // Before deleting excess parent rows, we need to clean up child references.
        // Find all tables in the temp space that reference this table.
        $fks = $schema->getForeignKeys($schema->getTables());
        $childFks = array_filter($fks, fn ($fk) => $fk['referenced_table'] === $table);

        foreach ($childFks as $fk) {
            $childTable = $tempDb->qualifiedTableName($fk['table']);
            $childColumn = $fk['column'];
            $refColumn = $fk['referenced_column'];

            // Delete child rows that reference parent rows being removed
            DB::statement(
                "DELETE FROM {$childTable} WHERE `{$childColumn}` NOT IN (
                    SELECT `{$refColumn}` FROM {$qualifiedTable}
                    ORDER BY `{$orderBy}` {$direction} LIMIT {$maxRows}
                )"
            );
        }

        // Now delete the excess rows from the parent table
        // Using a subquery approach compatible with MySQL
        DB::statement(
            "DELETE FROM {$qualifiedTable} WHERE `{$pkColumn}` NOT IN ("
            . implode(',', array_map(fn ($id) => DB::getPdo()->quote($id), $keepIds))
            . ')'
        );
    }
}
