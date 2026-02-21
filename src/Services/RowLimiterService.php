<?php

namespace Elliptic\Backfill\Services;

use Illuminate\Support\Facades\DB;

class RowLimiterService
{
    /**
     * Apply row limit to a table in the temp space.
     * Evaluates the full dependency graph statelessly against the source database,
     * and deletes any rows from the temp table that do not meet the keep conditions.
     */
    public function apply(
        string $table,
        TempDatabaseService $tempDb,
        SubsetResolverService $resolver,
        SchemaService $schema
    ): void {
        $pk = $schema->getPrimaryKey($table)[0] ?? 'id';
        $qualifiedTable = $tempDb->qualifiedTableName($table);
        
        $keepSql = $resolver->buildKeepQuery($table);

        // Delete any row from the temp table that is NOT in the precise keep query
        DB::statement("DELETE FROM {$qualifiedTable} WHERE `{$pk}` NOT IN ({$keepSql})");
    }
}
