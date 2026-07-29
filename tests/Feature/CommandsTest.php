<?php

use Elliptic\Backfill\Services\ImportService;
use Elliptic\Backfill\Services\SyncClient;
use Illuminate\Support\Facades\File;

it('blocks pull on non-allowed environments', function () {
    config(['backfill.client.allowed_environments' => ['local', 'staging']]);
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('backfill:pull')
        ->expectsOutputToContain('only allowed')
        ->assertExitCode(1);
});

it('supports backfill as an alias for backfill:pull', function () {
    config(['backfill.client.allowed_environments' => ['local', 'staging']]);
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('backfill')
        ->expectsOutputToContain('only allowed')
        ->assertExitCode(1);
});

it('shows status with no sync history', function () {
    $this->artisan('backfill:status')
        ->expectsOutputToContain('No sync history found')
        ->assertExitCode(0);
});

it('runs the install command successfully', function () {
    $this->artisan('backfill:install')
        ->expectsOutputToContain('Generated token')
        ->expectsOutputToContain('Environment Setup')
        ->assertExitCode(0);
})->skip(fn () => file_exists(base_path('.gitignore')) || file_exists(base_path('.env')), 'Skipped when .env or .gitignore exists (interactive prompts)');

it('registers the install command', function () {
    $this->artisan('backfill:install --help')
        ->expectsOutputToContain('Generate a sync token')
        ->assertExitCode(0);
});

it('downloads missing tables when a recent cache is only partially complete', function () {
    File::delete(storage_path('backfill-state.json'));

    $cacheDir = storage_path('app/backfill-partial-cache');
    File::deleteDirectory($cacheDir);
    File::ensureDirectoryExists($cacheDir);

    $manifest = [
        'server_time' => '2026-04-23T12:00:00Z',
        'table_order' => ['users', 'orders', 'products'],
        'tables' => [
            'users' => ['row_count' => 3, 'columns' => []],
            'orders' => ['row_count' => 2, 'columns' => []],
            'products' => ['row_count' => 1, 'columns' => []],
        ],
    ];

    File::put($cacheDir.'/.backfill-meta.json', json_encode([
        'downloaded_at' => now()->toIso8601String(),
        'mode' => 'full',
        'table_order' => ['users'],
        'table_info' => ['users' => ['row_count' => 3, 'columns' => []]],
    ], JSON_PRETTY_PRINT));
    File::put($cacheDir.'/users.sql', '-- cached users dump');

    $client = Mockery::mock(SyncClient::class);
    $client->shouldReceive('getManifest')
        ->once()
        ->with(null)
        ->andReturn($manifest);
    $client->shouldReceive('downloadTableDumpWithProgress')
        ->once()
        ->withArgs(fn ($table, $directory, $after, $onProgress) => $table === 'orders'
            && $directory === $cacheDir
            && $after === null
            && is_callable($onProgress))
        ->andReturnUsing(function ($table, $directory, $after, $onProgress) use ($cacheDir) {
            File::put($cacheDir.'/orders.sql', '-- downloaded orders dump');
            $onProgress([
                'chunk' => 2,
                'downloaded_rows' => 10000,
                'downloaded_bytes' => 8388608,
            ]);

            return ['path' => $cacheDir.'/orders.sql', 'meta' => []];
        });
    $client->shouldReceive('downloadTableDumpWithProgress')
        ->once()
        ->withArgs(fn ($table, $directory, $after, $onProgress) => $table === 'products'
            && $directory === $cacheDir
            && $after === null
            && is_callable($onProgress))
        ->andReturnUsing(function ($table, $directory, $after, $onProgress) use ($cacheDir) {
            File::put($cacheDir.'/products.sql', '-- downloaded products dump');

            return ['path' => $cacheDir.'/products.sql', 'meta' => []];
        });

    $importer = Mockery::mock(ImportService::class);
    $importer->shouldReceive('importSqlDump')->once()->withArgs(function ($table, $path, $isDelta) {
        return $table === 'users'
            && str_replace('\\', '/', $path) === str_replace('\\', '/', storage_path('app/backfill-partial-cache/users.sql'))
            && $isDelta === false;
    })->andReturn(3);
    $importer->shouldReceive('importSqlDump')->once()->withArgs(function ($table, $path, $isDelta) {
        return $table === 'orders'
            && str_replace('\\', '/', $path) === str_replace('\\', '/', storage_path('app/backfill-partial-cache/orders.sql'))
            && $isDelta === false;
    })->andReturn(2);
    $importer->shouldReceive('importSqlDump')->once()->withArgs(function ($table, $path, $isDelta) {
        return $table === 'products'
            && str_replace('\\', '/', $path) === str_replace('\\', '/', storage_path('app/backfill-partial-cache/products.sql'))
            && $isDelta === false;
    })->andReturn(1);

    app()->instance(SyncClient::class, $client);
    app()->instance(ImportService::class, $importer);

    try {
        $this->artisan('backfill:pull --full --force')
            ->expectsOutputToContain('Using local copy')
            ->expectsOutputToContain('Tables: 3')
            ->expectsOutputToContain('Downloading orders — chunk 2, 10,000 rows, 8.0 MB')
            ->assertExitCode(0);
    } finally {
        File::deleteDirectory($cacheDir);
        File::delete(storage_path('backfill-state.json'));
    }
});

it('aborts the sync and preserves an incomplete cache when a table download fails', function () {
    File::delete(storage_path('backfill-state.json'));

    foreach (glob(storage_path('app/backfill-*'), GLOB_ONLYDIR) ?: [] as $directory) {
        File::deleteDirectory($directory);
    }

    $manifest = [
        'server_time' => '2026-07-28T23:30:00Z',
        'table_order' => ['users', 'orders'],
        'tables' => [
            'users' => ['row_count' => 3, 'columns' => []],
            'orders' => ['row_count' => 2, 'columns' => []],
        ],
    ];

    $client = Mockery::mock(SyncClient::class);
    $client->shouldReceive('getManifest')
        ->once()
        ->with(null)
        ->andReturn($manifest);
    $client->shouldReceive('downloadTableDumpWithProgress')
        ->once()
        ->withArgs(fn ($table, $directory, $after) => $table === 'users' && $after === null)
        ->andReturnUsing(function ($table, $directory) {
            File::put($directory.'/users.sql', '-- downloaded users dump');

            return ['path' => $directory.'/users.sql', 'meta' => []];
        });
    $client->shouldReceive('downloadTableDumpWithProgress')
        ->once()
        ->withArgs(fn ($table, $directory, $after) => $table === 'orders' && $after === null)
        ->andThrow(new RuntimeException('production dump failed'));

    $importer = Mockery::mock(ImportService::class);
    $importer->shouldNotReceive('importSqlDump');

    app()->instance(SyncClient::class, $client);
    app()->instance(ImportService::class, $importer);

    try {
        $this->artisan('backfill:pull --full --force --fresh')
            ->expectsOutputToContain('Error downloading orders: production dump failed')
            ->expectsOutputToContain('Downloaded data is preserved')
            ->doesntExpectOutputToContain('Sync complete')
            ->assertExitCode(1);

        expect(File::exists(storage_path('backfill-state.json')))->toBeFalse();

        $cacheDirectories = glob(storage_path('app/backfill-*'), GLOB_ONLYDIR) ?: [];
        expect($cacheDirectories)->toHaveCount(1);

        $metadata = json_decode(
            File::get($cacheDirectories[0].'/.backfill-meta.json'),
            true,
        );

        expect($metadata['status'])->toBe('failed')
            ->and($metadata['failed_table'])->toBe('orders')
            ->and(File::exists($cacheDirectories[0].'/users.sql'))->toBeTrue()
            ->and(File::exists($cacheDirectories[0].'/orders.sql'))->toBeFalse();
    } finally {
        foreach (glob(storage_path('app/backfill-*'), GLOB_ONLYDIR) ?: [] as $directory) {
            File::deleteDirectory($directory);
        }
        File::delete(storage_path('backfill-state.json'));
    }
});

it('fails the sync without advancing its checkpoint when a table import fails', function () {
    File::delete(storage_path('backfill-state.json'));

    foreach (glob(storage_path('app/backfill-*'), GLOB_ONLYDIR) ?: [] as $directory) {
        File::deleteDirectory($directory);
    }

    $manifest = [
        'server_time' => '2026-07-29T16:00:00Z',
        'table_order' => ['users'],
        'tables' => [
            'users' => ['row_count' => 3, 'columns' => []],
        ],
    ];

    $client = Mockery::mock(SyncClient::class);
    $client->shouldReceive('getManifest')
        ->once()
        ->with(null)
        ->andReturn($manifest);
    $client->shouldReceive('downloadTableDumpWithProgress')
        ->once()
        ->withArgs(fn ($table, $directory, $after, $onProgress) => $table === 'users'
            && $after === null
            && is_callable($onProgress))
        ->andReturnUsing(function ($table, $directory) {
            File::put($directory.'/users.sql', '-- downloaded users dump');

            return ['path' => $directory.'/users.sql', 'meta' => []];
        });

    $importer = Mockery::mock(ImportService::class);
    $importer->shouldReceive('importSqlDump')
        ->once()
        ->andThrow(new RuntimeException('mysql import failed'));

    app()->instance(SyncClient::class, $client);
    app()->instance(ImportService::class, $importer);

    try {
        $this->artisan('backfill:pull --full --force --fresh')
            ->expectsOutputToContain('Error importing users: mysql import failed')
            ->expectsOutputToContain('sync checkpoint was not advanced')
            ->doesntExpectOutputToContain('Sync complete')
            ->assertExitCode(1);

        $state = json_decode(File::get(storage_path('backfill-state.json')), true);

        expect($state['last_completed_at'])->toBeNull()
            ->and($state['history'])->toHaveCount(1)
            ->and($state['history'][0]['completed_at'])->toBeNull();
    } finally {
        foreach (glob(storage_path('app/backfill-*'), GLOB_ONLYDIR) ?: [] as $directory) {
            File::deleteDirectory($directory);
        }
        File::delete(storage_path('backfill-state.json'));
    }
});
