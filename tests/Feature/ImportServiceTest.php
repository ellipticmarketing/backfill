<?php

use Elliptic\Backfill\Services\ImportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // Create a test table in the SQLite in-memory DB
    Schema::create('test_users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->timestamps();
    });

    $this->importer = new ImportService();
    $this->tempDir = sys_get_temp_dir() . '/backfill-test-' . time();
    @mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    Schema::dropIfExists('test_users');

    // Clean up temp files
    $files = glob($this->tempDir . '/*');
    foreach ($files as $file) {
        @unlink($file);
    }
    @rmdir($this->tempDir);
});

it('imports a SQL dump file using the PHP fallback for SQLite', function () {
    // Write a SQL dump that inserts rows
    $sql = <<<SQL
INSERT INTO `test_users` (`id`, `name`, `email`, `created_at`, `updated_at`) VALUES (1, 'User_1', 'uuid1@example.test', '2024-01-01 00:00:00', '2024-01-01 00:00:00');
INSERT INTO `test_users` (`id`, `name`, `email`, `created_at`, `updated_at`) VALUES (2, 'User_2', 'uuid2@example.test', '2024-01-01 00:00:00', '2024-01-01 00:00:00');
SQL;

    $filePath = $this->tempDir . '/test_users.sql';
    file_put_contents($filePath, $sql);

    $rowCount = $this->importer->importSqlDump('test_users', $filePath, false);

    expect($rowCount)->toBe(2);
    expect(DB::table('test_users')->count())->toBe(2);
    expect(DB::table('test_users')->where('id', 1)->value('name'))->toBe('User_1');
});

it('handles full import by truncating first', function () {
    // Pre-populate
    DB::table('test_users')->insert([
        'name' => 'Existing', 'email' => 'existing@test.com',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $sql = "INSERT INTO `test_users` (`id`, `name`, `email`, `created_at`, `updated_at`) VALUES (10, 'NewUser', 'new@example.test', '2024-01-01 00:00:00', '2024-01-01 00:00:00');\n";
    $filePath = $this->tempDir . '/test_users.sql';
    file_put_contents($filePath, $sql);

    $rowCount = $this->importer->importSqlDump('test_users', $filePath, false);

    expect($rowCount)->toBe(1);
    expect(DB::table('test_users')->where('name', 'Existing')->exists())->toBeFalse();
    expect(DB::table('test_users')->where('name', 'NewUser')->exists())->toBeTrue();
});

it('handles empty dump files gracefully', function () {
    $filePath = $this->tempDir . '/test_users.sql';
    file_put_contents($filePath, '');

    $rowCount = $this->importer->importSqlDump('test_users', $filePath, false);

    expect($rowCount)->toBe(0);
});

it('rewrites temp table names to the real table name', function () {
    // Simulate a dump that references the temp table name
    $sql = "INSERT INTO `_backfill_test_users` (`id`, `name`, `email`, `created_at`, `updated_at`) VALUES (1, 'User_1', 'test@example.test', '2024-01-01 00:00:00', '2024-01-01 00:00:00');\n";

    $filePath = $this->tempDir . '/test_users.sql';
    file_put_contents($filePath, $sql);

    $rowCount = $this->importer->importSqlDump('test_users', $filePath, false);

    expect($rowCount)->toBe(1);
    expect(DB::table('test_users')->where('id', 1)->value('name'))->toBe('User_1');
});

it('throws exception for missing dump file', function () {
    $this->importer->importSqlDump('test_users', '/nonexistent/path.sql', false);
})->throws(RuntimeException::class);

it('returns local column listing', function () {
    $columns = $this->importer->getLocalColumns('test_users');

    expect($columns)->toContain('id');
    expect($columns)->toContain('name');
    expect($columns)->toContain('email');
});
