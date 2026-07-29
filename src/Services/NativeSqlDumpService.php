<?php

namespace Elliptic\Backfill\Services;

use RuntimeException;
use Stringable;

class NativeSqlDumpService
{
    /**
     * Stream a table as complete INSERT statements without spawning a process.
     */
    public function stream(
        string $table,
        TempDatabaseService $tempDatabase,
        array $columns,
        array $primaryKey,
    ): void {
        if (empty($columns)) {
            return;
        }

        $chunkSize = max(1, (int) config('backfill.server.chunk_size', 5000));
        $offset = 0;
        $targetTable = $tempDatabase->getStrategy() === 'tables'
            ? '_backfill_'.$table
            : $table;
        $quotedColumns = implode(', ', array_map($this->quoteIdentifier(...), $columns));

        do {
            $query = $tempDatabase->queryBuilder($table)
                ->select($columns)
                ->offset($offset)
                ->limit($chunkSize);

            foreach ($primaryKey as $column) {
                $query->orderBy($column);
            }

            $rows = $query->get();

            if ($rows->isEmpty()) {
                break;
            }

            echo 'INSERT INTO '.$this->quoteIdentifier($targetTable)
                ." ({$quotedColumns}) VALUES\n";

            foreach ($rows as $index => $row) {
                $rowValues = array_map(
                    fn (string $column): string => $this->quoteValue($row->{$column}),
                    $columns,
                );

                echo $index === 0 ? '' : ",\n";
                echo '('.implode(', ', $rowValues).')';
            }

            echo ";\n";
            flush();

            $rowCount = $rows->count();
            $offset += $rowCount;
        } while ($rowCount === $chunkSize);
    }

    protected function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    protected function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        if ($value instanceof Stringable) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            throw new RuntimeException(
                'Backfill cannot serialize a database value of type '.get_debug_type($value).'.'
            );
        }

        if ($value === '') {
            return "''";
        }

        return "X'".bin2hex($value)."'";
    }
}
