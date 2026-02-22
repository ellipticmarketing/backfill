<?php

namespace Elliptic\Backfill\Http\Controllers;

use Elliptic\Backfill\Services\SchemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManifestController
{
    public function __invoke(Request $request, SchemaService $schema): JsonResponse
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if ($driver !== 'mysql' && $driver !== 'mariadb' && ! app()->runningUnitTests()) {
            return response()->json([
                'error' => 'Backfill requires a MySQL or MariaDB connection. Current driver: ' . $driver,
            ], 400);
        }

        try {
            $excludedTables = config('backfill.exclude_tables', []);
            $tables = $schema->getTables($excludedTables);
            $sorted = $schema->topologicalSort($tables);

            $manifest = [];

            foreach ($sorted as $table) {
                $columns = $schema->getColumns($table);
                $primaryKey = $schema->getPrimaryKey($table);
                $hasTimestamps = $schema->hasTimestamps($table);
                $rowCount = $schema->getRowCount($table);

                $manifest[$table] = [
                    'columns' => $columns,
                    'primary_key' => $primaryKey,
                    'has_timestamps' => $hasTimestamps,
                    'row_count' => $rowCount,
                    'has_limit' => isset(config('backfill.limits', [])[$table]),
                    'has_sanitization' => isset(config('backfill.sanitize', [])[$table]),
                ];
            }

            return response()->json([
                'tables' => $manifest,
                'table_order' => $sorted,
                'database' => config('database.connections.' . config('database.default') . '.database'),
                'server_time' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
