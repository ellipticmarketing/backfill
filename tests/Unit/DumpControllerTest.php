<?php

use Elliptic\Backfill\Http\Controllers\DumpController;
use Elliptic\Backfill\Services\RowLimiterService;
use Elliptic\Backfill\Services\SanitizationService;
use Elliptic\Backfill\Services\SchemaService;
use Elliptic\Backfill\Services\ServerRequirementsService;
use Elliptic\Backfill\Services\SubsetResolverService;
use Elliptic\Backfill\Services\TempDatabaseService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

it('returns a clear error before preparing data when server requirements are missing', function () {
    config(['backfill.exclude_tables' => []]);

    $schema = Mockery::mock(SchemaService::class);
    $schema->shouldReceive('getTables')->with([])->once()->andReturn(['users']);

    $tempDb = Mockery::mock(TempDatabaseService::class);
    $tempDb->shouldNotReceive('prepare');
    $tempDb->shouldReceive('cleanup')->with('users')->once();

    $requirements = Mockery::mock(ServerRequirementsService::class);
    $requirements->shouldReceive('ensureRequirementsAreMet')
        ->once()
        ->andThrow(new RuntimeException(
            'Backfill server requirement not met: The PHP proc_open function is disabled.'
        ));

    $response = (new DumpController)(
        Request::create('/backfill/dump/users'),
        'users',
        $schema,
        $tempDb,
        Mockery::mock(SanitizationService::class),
        Mockery::mock(RowLimiterService::class),
        $requirements,
    );

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getContent())->toContain('proc_open function is disabled');
});

it('passes the resolved subset query into the initial temporary copy', function () {
    config([
        'backfill.exclude_tables' => [],
        'backfill.limits' => [
            'users' => ['max_rows' => 2, 'order_by' => 'id', 'direction' => 'desc'],
        ],
        'database.connections.testing.username' => 'test',
        'database.connections.testing.password' => '',
    ]);

    $schema = Mockery::mock(SchemaService::class);
    $schema->shouldReceive('getTables')->with([])->once()->andReturn(['users']);
    $schema->shouldReceive('getTables')->withNoArgs()->once()->andReturn(['users']);
    $schema->shouldReceive('getForeignKeys')->with(['users'])->once()->andReturn([]);
    $schema->shouldReceive('getPrimaryKey')->with('users')->andReturn(['id']);
    $schema->shouldReceive('hasTimestamps')->with('users')->once()->andReturnTrue();

    $tempDb = Mockery::mock(TempDatabaseService::class);
    $tempDb->shouldReceive('getSourceDatabase')->andReturn('production');
    $tempDb->shouldReceive('prepare')
        ->once()
        ->with(
            'users',
            'SELECT `id` FROM `users` WHERE `id` IN (SELECT `id` FROM (SELECT `id` FROM `users` ORDER BY `id` DESC LIMIT 2) as _base_users)',
            'id',
        );
    $tempDb->shouldReceive('getTempDatabaseName')->once()->andReturn('_backfill_test');
    $tempDb->shouldReceive('getStrategy')->once()->andReturn('database');

    $limiter = Mockery::mock(RowLimiterService::class);
    $limiter->shouldReceive('apply')
        ->once()
        ->with(
            'users',
            $tempDb,
            Mockery::type(SubsetResolverService::class),
            $schema,
        );

    $requirements = Mockery::mock(ServerRequirementsService::class);
    $requirements->shouldReceive('ensureRequirementsAreMet')
        ->once()
        ->andReturn('mysqldump');

    $response = (new DumpController)(
        Request::create('/backfill/dump/users'),
        'users',
        $schema,
        $tempDb,
        Mockery::mock(SanitizationService::class),
        $limiter,
        $requirements,
    );

    expect($response)->toBeInstanceOf(StreamedResponse::class);
});

it('rejects a configured limit for a table without a primary key before copying it', function () {
    config([
        'backfill.exclude_tables' => [],
        'backfill.limits' => [
            'settings' => ['max_rows' => 10],
        ],
    ]);

    $schema = Mockery::mock(SchemaService::class);
    $schema->shouldReceive('getTables')->with([])->once()->andReturn(['settings']);
    $schema->shouldReceive('getPrimaryKey')->with('settings')->once()->andReturn([]);

    $tempDb = Mockery::mock(TempDatabaseService::class);
    $tempDb->shouldNotReceive('prepare');
    $tempDb->shouldReceive('cleanup')->with('settings')->once();

    $requirements = Mockery::mock(ServerRequirementsService::class);
    $requirements->shouldReceive('ensureRequirementsAreMet')
        ->once()
        ->andReturn('mysqldump');

    $response = (new DumpController)(
        Request::create('/backfill/dump/settings'),
        'settings',
        $schema,
        $tempDb,
        Mockery::mock(SanitizationService::class),
        Mockery::mock(RowLimiterService::class),
        $requirements,
    );

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getContent())
        ->toContain("Cannot limit table 'settings' because it does not have a primary key.");
});
