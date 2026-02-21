<?php

namespace Elliptic\Backfill\Services;

use Illuminate\Support\Facades\DB;

class SanitizationService
{
    /**
     * Known bcrypt hash of 'password' — used for the 'hash' sanitization type.
     */
    protected const PASSWORD_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    /**
     * Apply sanitization rules to a table's data in the temp space via SQL UPDATEs.
     * No PHP row iteration — everything runs as database operations.
     */
    public function sanitize(string $table, array $rules, TempDatabaseService $tempDb): void
    {
        foreach ($rules as $column => $rule) {
            $type = $rule['type'] ?? null;
            $excludes = $rule['exclude'] ?? [];

            if (! $type) {
                continue;
            }

            $expression = $this->buildExpression($type, $table, $column);

            if ($expression === null) {
                continue;
            }

            $sql = $this->buildUpdateSql($table, $column, $expression, $excludes, $tempDb);
            DB::statement($sql);
        }
    }

    /**
     * Build the SQL expression for a given sanitization type.
     */
    protected function buildExpression(string $type, string $table, string $column): ?string
    {
        return match ($type) {
            'email' => "CONCAT(UUID(), '@example.test')",
            'name' => "CONCAT('User_', `id`)",
            'phone' => "CONCAT('+1555', LPAD(`id`, 7, '0'))",
            'text' => "CONCAT('text_', MD5(RAND()))",
            'hash' => "'" . self::PASSWORD_HASH . "'",
            'null' => 'NULL',
            'address' => "CONCAT(`id`, ' Example St')",
            default => throw new \InvalidArgumentException("Unknown sanitization type: {$type}"),
        };
    }

    /**
     * Build the full UPDATE SQL statement with optional CASE WHEN for exclusion patterns.
     *
     * If exclude patterns are provided, the generated SQL looks like:
     *   UPDATE table SET column = CASE
     *     WHEN column LIKE '%@company.com' THEN column   -- keep original
     *     WHEN column LIKE 'admin@%' THEN column         -- keep original
     *     ELSE CONCAT(UUID(), '@example.test')            -- sanitize
     *   END
     */
    protected function buildUpdateSql(
        string $table,
        string $column,
        string $expression,
        array $excludes,
        TempDatabaseService $tempDb
    ): string {
        $qualifiedTable = $tempDb->qualifiedTableName($table);

        if (empty($excludes)) {
            return "UPDATE {$qualifiedTable} SET `{$column}` = {$expression}";
        }

        // Build CASE WHEN to preserve rows matching exclude patterns
        $whenClauses = [];
        foreach ($excludes as $pattern) {
            $likePattern = str_replace('*', '%', $pattern);
            $escaped = addslashes($likePattern);
            $whenClauses[] = "WHEN `{$column}` LIKE '{$escaped}' THEN `{$column}`";
        }

        $caseStatement = "CASE\n"
            . implode("\n", $whenClauses) . "\n"
            . "ELSE {$expression}\n"
            . 'END';

        return "UPDATE {$qualifiedTable} SET `{$column}` = {$caseStatement}";
    }
}
