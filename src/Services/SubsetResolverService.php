<?php

namespace Elliptic\Backfill\Services;

class SubsetResolverService
{
    protected SchemaService $schema;

    protected array $limits;

    protected string $sourceDatabase;

    public function __construct(SchemaService $schema, array $limits, string $sourceDatabase)
    {
        $this->schema = $schema;
        $this->limits = $limits;
        $this->sourceDatabase = $sourceDatabase;
    }

    protected function getTableRef(string $table): string
    {
        return app()->runningUnitTests() ? "`{$table}`" : "`{$this->sourceDatabase}`.`{$table}`";
    }

    /**
     * Builds a purely stateless SQL query against the SOURCE database that returns
     * all primary keys for the rows of `$table` that should be kept.
     *
     * This organically handles Top-Down limits (orphaned children are deleted) and
     * Bottom-Up inclusions (parents of kept children are kept).
     */
    public function buildKeepQuery(string $table, array $path = []): string
    {
        $pk = $this->schema->getPrimaryKey($table)[0] ?? 'id';

        // Prevent infinite loops in circular foreign keys
        if (in_array($table, $path)) {
            $tableRef = $this->getTableRef($table);

            return "SELECT `{$pk}` FROM {$tableRef}";
        }
        $path[] = $table;

        // 1. Intrinsic Limit (e.g. max_rows = 1000)
        $queries = [$this->getIntrinsicQuery($table)];

        // 2. Bottom-Up Inclusions
        // If a child table has a specific limit configured, its "kept" rows force their parents to be kept.
        $allFks = $this->schema->getForeignKeys($this->schema->getTables());
        $childFks = array_filter($allFks, fn ($fk) => $fk['referenced_table'] === $table);

        foreach ($childFks as $fk) {
            $childTable = $fk['table'];
            // Only apply bottom-up inclusion from children that actually have a configured limit.
            // If the child is unlimited, it just means "give me all cars for valid users".
            if (! empty($this->limits[$childTable])) {
                $childFkCol = $fk['column'];
                $childPk = $this->schema->getPrimaryKey($childTable)[0] ?? 'id';
                $childKeepQuery = $this->buildKeepQuery($childTable, $path);

                $childTableRef = $this->getTableRef($childTable);
                $queries[] = "SELECT `{$childFkCol}` FROM {$childTableRef} WHERE `{$childPk}` IN ({$childKeepQuery}) AND `{$childFkCol}` IS NOT NULL";
            }
        }

        $baseSet = implode(' UNION ', $queries);

        // 3. Top-Down Exclusions
        // Keep a candidate row ONLY if its parent(s) are ALSO kept.
        $parentFks = array_filter($allFks, fn ($fk) => $fk['table'] === $table);

        $finalWheres = ["`{$pk}` IN ({$baseSet})"];

        foreach ($parentFks as $fk) {
            $parentTable = $fk['referenced_table'];
            $parentCol = $fk['column'];

            // Optimization: If the parent has NO limit, and NO children with limits,
            // the parent keep query evaluates to "all rows". We only add the where
            // clause if it actually restricts the dataset.
            if ($this->tableHasLimitsAnywhere($parentTable, [])) {
                $parentKeepQuery = $this->buildKeepQuery($parentTable, $path);
                // Allow NULL foreign keys (optional relationships shouldn't be discarded)
                $finalWheres[] = "(`{$parentCol}` IS NULL OR `{$parentCol}` IN ({$parentKeepQuery}))";
            }
        }

        $tableRef = $this->getTableRef($table);

        return "SELECT `{$pk}` FROM {$tableRef} WHERE ".implode(' AND ', $finalWheres);
    }

    protected function getIntrinsicQuery(string $table): string
    {
        $pk = $this->schema->getPrimaryKey($table)[0] ?? 'id';
        $config = $this->limits[$table] ?? [];

        $tableRef = $this->getTableRef($table);
        $query = "SELECT `{$pk}` FROM {$tableRef}";

        // No limits = keep everything
        if (empty($config)) {
            return $query;
        }

        $orderBy = $config['order_by'] ?? $pk;
        $direction = strtoupper($config['direction'] ?? 'DESC');
        if (! in_array($direction, ['ASC', 'DESC'])) {
            $direction = 'DESC';
        }

        $keepDays = $config['keep_days'] ?? null;
        $maxRows = $config['max_rows'] ?? null;

        $wheres = [];
        if ($keepDays !== null) {
            $cutoff = now()->subDays($keepDays)->toDateTimeString();
            $wheres[] = "`{$orderBy}` >= '{$cutoff}'";
        }

        if (! empty($wheres)) {
            $query .= ' WHERE '.implode(' AND ', $wheres);
        }

        if ($maxRows !== null) {
            $query .= " ORDER BY `{$orderBy}` {$direction} LIMIT {$maxRows}";
        }

        // MySQL requires derived tables to use LIMIT inside an IN(...) clause or UNION
        return "SELECT `{$pk}` FROM ({$query}) as _base_{$table}";
    }

    /**
     * Determines if a table could ever restrict rows Top-Down.
     * True if the table has an intrinsic limit, OR if any of its descendants have constraints.
     */
    protected function tableHasLimitsAnywhere(string $table, array $path = []): bool
    {
        if (in_array($table, $path)) {
            return false;
        }
        $path[] = $table;

        if (! empty($this->limits[$table])) {
            return true;
        }

        $allFks = $this->schema->getForeignKeys($this->schema->getTables());
        $childFks = array_filter($allFks, fn ($fk) => $fk['referenced_table'] === $table);

        foreach ($childFks as $fk) {
            if ($this->tableHasLimitsAnywhere($fk['table'], $path)) {
                return true;
            }
        }

        return false;
    }
}
