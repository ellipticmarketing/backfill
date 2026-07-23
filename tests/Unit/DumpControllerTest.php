<?php

use Elliptic\Backfill\Http\Controllers\DumpController;
use Elliptic\Backfill\Services\RowLimiterService;
use Elliptic\Backfill\Services\SanitizationService;
use Elliptic\Backfill\Services\SchemaService;
use Elliptic\Backfill\Services\ServerRequirementsService;
use Elliptic\Backfill\Services\TempDatabaseService;
use Illuminate\Http\Request;

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
