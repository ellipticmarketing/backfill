<?php

namespace Elliptic\Backfill\Tests;

use Elliptic\Backfill\BackfillServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            BackfillServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('backfill.auth_token', 'test-token-123');
        $app['config']->set('backfill.server.enabled', true);
        $app['config']->set('backfill.client.source_url', 'https://production.example.com');
        $app['config']->set('backfill.client.allowed_environments', ['testing']);
    }
}
