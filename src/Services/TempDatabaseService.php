<?php

namespace Elliptic\Backfill\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TempDatabaseService
{
    protected ?string $tempDatabase = null;

    protected array $tempTables = [];

    protected array $preparedDatabaseTables = [];

    protected bool $shouldDropTempDatabase = true;

    protected string $strategy;

    protected string $sourceDatabase;

    protected string $connectionName;

    public function __construct()
    {
        $this->strategy = config('backfill.server.temp_strategy', 'database');
        $this->sourceDatabase = config('database.connections.' . config('database.default') . '.database');
        $this->connectionName = $this->resolveConnectionName();

        // Best-effort cleanup on unexpected shutdown (OOM, fatal error, etc.)
        register_shutdown_function(function () {
            $this->cleanupAll();
        });
    }

    /**
     * Resolve which DB connection to use for temp operations.
     * If alternate credentials are configured, register a dynamic connection.
     */
    protected function resolveConnectionName(): string
    {
        $tempUsername = config('backfill.server.temp_username');
        $tempPassword = config('backfill.server.temp_password');

        if ($tempUsername) {
            $defaultConnection = config('database.default');
            $baseConfig = config("database.connections.{$defaultConnection}");

            // Register a dynamic connection with the alternate credentials
            config([
                'database.connections.BACKFILL_temp' => array_merge($baseConfig, [
                    'username' => $tempUsername,
                    'password' => $tempPassword ?? '',
                ]),
            ]);

            return 'BACKFILL_temp';
        }

        return config('database.default');
    }

    /**
     * Get the DB facade for the resolved connection.
     */
    protected function db(): \Illuminate\Database\Connection
    {
        return DB::connection($this->connectionName);
    }

    /**
     * Prepare a table for safe reading — copy it to a temp space and sanitize there.
     */
    public function prepare(string $table): void
    {
        if ($this->strategy === 'database') {
            $this->prepareWithDatabase($table);
        } else {
            $this->prepareWithTables($table);
        }
    }

    /**
     * Get a query builder for the table in the temp space.
     */
    public function queryBuilder(string $table): Builder
    {
        return $this->db()->table(DB::raw($this->qualifiedTableName($table)));
    }

    /**
     * Get the qualified table name (including temp database/prefix).
     */
    public function qualifiedTableName(string $table): string
    {
        if ($this->strategy === 'database') {
            return "`{$this->tempDatabase}`.`{$table}`";
        }

        $tempName = $this->tempTables[$table] ?? "_backfill_{$table}";

        return "`{$tempName}`";
    }

    /**
     * Get the connection name being used for temp operations.
     */
    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * Get the temp database name (only meaningful for 'database' strategy).
     */
    public function getTempDatabaseName(): ?string
    {
        return $this->tempDatabase;
    }

    /**
     * Get the source (production) database name.
     */
    public function getSourceDatabase(): string
    {
        return $this->sourceDatabase;
    }

    /**
     * Get the current temp strategy ('database' or 'tables').
     */
    public function getStrategy(): string
    {
        return $this->strategy;
    }

    /**
     * Clean up temp resources for a specific table.
     */
    public function cleanup(string $table): void
    {
        if ($this->strategy === 'database') {
            if ($this->tempDatabase) {
                $this->db()->statement("DROP TABLE IF EXISTS `{$this->tempDatabase}`.`{$table}`");

                $key = array_search($table, $this->preparedDatabaseTables);
                if ($key !== false) {
                    unset($this->preparedDatabaseTables[$key]);
                }

                if ($this->shouldDropTempDatabase) {
                    // Check if the temp database is now empty and drop it
                    $remaining = $this->db()->select(
                        'SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ?',
                        [$this->tempDatabase]
                    );

                    if (($remaining[0]->count ?? 0) === 0) {
                        try {
                            $this->db()->statement("DROP DATABASE IF EXISTS `{$this->tempDatabase}`");
                        } catch (\Throwable $e) {}
                        $this->tempDatabase = null;
                    }
                }
            }
        } else {
            $tempName = $this->tempTables[$table] ?? "_backfill_{$table}";
            $this->db()->statement("DROP TABLE IF EXISTS `{$tempName}`");
            unset($this->tempTables[$table]);
        }
    }

    /**
     * Force cleanup of all temp resources.
     */
    public function cleanupAll(): void
    {
        if ($this->strategy === 'database' && $this->tempDatabase) {
            if ($this->shouldDropTempDatabase) {
                try {
                    $this->db()->statement("DROP DATABASE IF EXISTS `{$this->tempDatabase}`");
                } catch (\Throwable $e) {}
                $this->tempDatabase = null;
            } else {
                foreach ($this->preparedDatabaseTables as $table) {
                    try {
                        $this->db()->statement("DROP TABLE IF EXISTS `{$this->tempDatabase}`.`{$table}`");
                    } catch (\Throwable $e) {}
                }
                $this->preparedDatabaseTables = [];
            }
        }

        foreach (array_keys($this->tempTables) as $table) {
            $this->cleanup($table);
        }
    }

    // -------------------------------------------------------------------------
    // Strategy: Temporary Database
    // -------------------------------------------------------------------------

    protected function prepareWithDatabase(string $table): void
    {
        $this->ensureTempDatabase();

        $this->preparedDatabaseTables[] = $table;

        // Create the table structure and copy data
        $this->db()->statement(
            "CREATE TABLE IF NOT EXISTS `{$this->tempDatabase}`.`{$table}` LIKE `{$this->sourceDatabase}`.`{$table}`"
        );
        $this->db()->statement(
            "INSERT INTO `{$this->tempDatabase}`.`{$table}` SELECT * FROM `{$this->sourceDatabase}`.`{$table}`"
        );
    }

    protected function ensureTempDatabase(): void
    {
        if ($this->tempDatabase) {
            return;
        }

        $configuredName = config('backfill.server.temp_database');

        if ($configuredName) {
            $this->tempDatabase = $configuredName;
            $this->shouldDropTempDatabase = false;
        } else {
            $this->tempDatabase = '_backfill_temp_' . time() . '_' . mt_rand(1000, 9999);
            $this->shouldDropTempDatabase = true;
        }

        try {
            $exists = $this->db()->select(
                'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
                [$this->tempDatabase]
            );

            if (empty($exists)) {
                $this->db()->statement("CREATE DATABASE `{$this->tempDatabase}`");
            }
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Failed to create or access temporary database '{$this->tempDatabase}'. "
                . 'Ensure the database user has access or CREATE DATABASE privileges '
                . '(you can configure alternate credentials via BACKFILL_TEMP_USERNAME / BACKFILL_TEMP_PASSWORD), '
                . "or set backfill.server.temp_strategy to 'tables'. "
                . "Original error: {$e->getMessage()}"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Strategy: Temporary Tables (same database)
    // -------------------------------------------------------------------------

    protected function prepareWithTables(string $table): void
    {
        $tempName = '_backfill_' . $table;
        $this->tempTables[$table] = $tempName;

        $this->db()->statement("DROP TABLE IF EXISTS `{$tempName}`");

        if (app()->runningUnitTests()) {
            $this->db()->statement("CREATE TABLE `{$tempName}` AS SELECT * FROM `{$table}`");
            return;
        }

        $this->db()->statement("CREATE TABLE `{$tempName}` LIKE `{$table}`");
        $this->db()->statement("INSERT INTO `{$tempName}` SELECT * FROM `{$table}`");
    }
}
