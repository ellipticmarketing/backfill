<?php

namespace Elliptic\Backfill\Tests\Unit;

use Elliptic\Backfill\Services\TempDatabaseService;
use Elliptic\Backfill\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TempDatabaseServiceTest extends TestCase
{
    public function test_tables_strategy_copies_only_rows_selected_by_the_keep_query(): void
    {
        Schema::create('logs', function ($table) {
            $table->id();
            $table->string('message');
        });

        DB::table('logs')->insert([
            ['id' => 1, 'message' => 'First'],
            ['id' => 2, 'message' => 'Second'],
            ['id' => 3, 'message' => 'Third'],
        ]);

        config(['backfill.server.temp_strategy' => 'tables']);

        $tempDatabase = new TempDatabaseService;
        $tempDatabase->prepare(
            'logs',
            'SELECT `id` FROM `logs` WHERE `id` >= 2',
            'id',
        );

        $remainingIds = $tempDatabase->queryBuilder('logs')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([2, 3], $remainingIds);

        $tempDatabase->cleanup('logs');
        Schema::dropIfExists('logs');
    }

    public function test_tables_strategy_preserves_full_copy_behavior_without_a_keep_query(): void
    {
        Schema::create('logs', function ($table) {
            $table->id();
            $table->string('message');
        });

        DB::table('logs')->insert([
            ['id' => 1, 'message' => 'First'],
            ['id' => 2, 'message' => 'Second'],
            ['id' => 3, 'message' => 'Third'],
        ]);

        config(['backfill.server.temp_strategy' => 'tables']);

        $tempDatabase = new TempDatabaseService;
        $tempDatabase->prepare('logs');

        $this->assertSame(
            [1, 2, 3],
            $tempDatabase->queryBuilder('logs')->orderBy('id')->pluck('id')->all(),
        );

        $tempDatabase->cleanup('logs');
        Schema::dropIfExists('logs');
    }

    public function test_database_strategy_replaces_a_stale_table_and_applies_the_keep_query(): void
    {
        config([
            'backfill.server.temp_strategy' => 'database',
            'backfill.server.temp_database' => '_backfill_test',
            'database.connections.testing.database' => 'production',
        ]);

        $connection = \Mockery::mock(Connection::class);
        DB::shouldReceive('connection')
            ->with('testing')
            ->andReturn($connection);

        $connection->shouldReceive('select')
            ->once()
            ->with(
                'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
                ['_backfill_test'],
            )
            ->andReturn([(object) ['SCHEMA_NAME' => '_backfill_test']]);
        $connection->shouldReceive('statement')
            ->once()
            ->with('DROP TABLE IF EXISTS `_backfill_test`.`logs`')
            ->andReturnTrue()
            ->ordered();
        $connection->shouldReceive('statement')
            ->once()
            ->with('CREATE TABLE `_backfill_test`.`logs` LIKE `production`.`logs`')
            ->andReturnTrue()
            ->ordered();
        $connection->shouldReceive('statement')
            ->once()
            ->with(
                'INSERT INTO `_backfill_test`.`logs` SELECT * FROM `production`.`logs` WHERE `id` IN (SELECT `id` FROM `production`.`logs` LIMIT 10)',
            )
            ->andReturnTrue()
            ->ordered();

        $tempDatabase = new TempDatabaseService;
        $tempDatabase->prepare(
            'logs',
            'SELECT `id` FROM `production`.`logs` LIMIT 10',
            'id',
        );
    }
}
