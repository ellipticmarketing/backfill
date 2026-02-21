<?php

namespace Elliptic\Backfill\Tests\Unit;

use Elliptic\Backfill\Services\RowLimiterService;
use Elliptic\Backfill\Services\SchemaService;
use Elliptic\Backfill\Services\TempDatabaseService;
use Elliptic\Backfill\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RowLimiterServiceTest extends TestCase
{
    protected RowLimiterService $service;
    protected TempDatabaseService $tempDb;
    protected SchemaService $schema;

    protected function setUp(): void
    {
        parent::setUp();

        // Create actual DB tables for the test to work against
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('logs', function ($table) {
            $table->id();
            $table->string('message');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users');
        });

        config(['backfill.server.temp_strategy' => 'tables']);
        $this->tempDb = new TempDatabaseService();
        
        $this->schema = \Mockery::mock(SchemaService::class);
        $this->schema->shouldReceive('getPrimaryKey')->andReturn(['id']);
        $this->schema->shouldReceive('getTables')->andReturn(['users', 'logs']);
        $this->schema->shouldReceive('getForeignKeys')->andReturn([
            ['table' => 'logs', 'column' => 'user_id', 'referenced_table' => 'users', 'referenced_column' => 'id']
        ]);
        
        $this->service = new RowLimiterService();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('logs');
        Schema::dropIfExists('users');
        
        parent::tearDown();
    }

    public function test_it_keeps_only_max_rows()
    {
        DB::table('logs')->insert([
            ['id' => 1, 'message' => 'First'],
            ['id' => 2, 'message' => 'Second'],
            ['id' => 3, 'message' => 'Third'],
        ]);

        $this->tempDb->prepare('logs');

        $this->service->apply(
            'logs',
            ['max_rows' => 2, 'order_by' => 'id', 'direction' => 'desc'],
            $this->tempDb,
            $this->schema
        );

        $qualifiedLogs = $this->tempDb->qualifiedTableName('logs');
        $count = DB::table(DB::raw($qualifiedLogs))->count();
        $remainingIds = DB::table(DB::raw($qualifiedLogs))->pluck('id')->toArray();

        // Should keep IDs 2 and 3 (the highest IDs due to DESC)
        $this->assertEquals(2, $count);
        $this->assertContains(2, $remainingIds);
        $this->assertContains(3, $remainingIds);
        $this->assertNotContains(1, $remainingIds);
    }

    public function test_it_limits_rows_based_on_keep_days()
    {
        // 3 logs, 1 is recent (2 days ago), 2 are old (10 and 20 days ago)
        DB::table('logs')->insert([
            ['id' => 1, 'message' => 'New', 'created_at' => now()->subDays(2)],
            ['id' => 2, 'message' => 'Old', 'created_at' => now()->subDays(10)],
            ['id' => 3, 'message' => 'Older', 'created_at' => now()->subDays(20)],
        ]);

        $this->tempDb->prepare('logs');

        $this->service->apply(
            'logs',
            ['keep_days' => 5, 'order_by' => 'created_at'],
            $this->tempDb,
            $this->schema
        );

        $qualifiedLogs = $this->tempDb->qualifiedTableName('logs');
        $count = DB::table(DB::raw($qualifiedLogs))->count();
        $remainingIds = DB::table(DB::raw($qualifiedLogs))->pluck('id')->toArray();

        $this->assertEquals(1, $count);
        $this->assertContains(1, $remainingIds);
        $this->assertNotContains(2, $remainingIds);
        $this->assertNotContains(3, $remainingIds);
    }

    public function test_it_combines_keep_days_and_max_rows()
    {
        // 4 logs within the last 5 days
        DB::table('logs')->insert([
            ['id' => 1, 'message' => 'Log 1', 'created_at' => now()->subDays(1)],
            ['id' => 2, 'message' => 'Log 2', 'created_at' => now()->subDays(2)],
            ['id' => 3, 'message' => 'Log 3', 'created_at' => now()->subDays(3)],
            ['id' => 4, 'message' => 'Log 4', 'created_at' => now()->subDays(10)], // Too old
        ]);

        $this->tempDb->prepare('logs');

        $this->service->apply(
            'logs',
            ['keep_days' => 5, 'max_rows' => 2, 'order_by' => 'created_at', 'direction' => 'desc'],
            $this->tempDb,
            $this->schema
        );

        $qualifiedLogs = $this->tempDb->qualifiedTableName('logs');
        $count = DB::table(DB::raw($qualifiedLogs))->count();
        $remainingIds = DB::table(DB::raw($qualifiedLogs))->pluck('id')->toArray();

        // Should only keep the 2 most recent logs out of those that fall within keep_days
        $this->assertEquals(2, $count);
        $this->assertContains(1, $remainingIds);
        $this->assertContains(2, $remainingIds);
        $this->assertNotContains(3, $remainingIds);
        $this->assertNotContains(4, $remainingIds);
    }

    public function test_it_truncates_if_no_rows_match_keep_days()
    {
        DB::table('logs')->insert([
            ['id' => 1, 'message' => 'Old', 'created_at' => now()->subDays(10)],
            ['id' => 2, 'message' => 'Older', 'created_at' => now()->subDays(20)],
        ]);

        $this->tempDb->prepare('logs');

        $this->service->apply(
            'logs',
            ['keep_days' => 5, 'order_by' => 'created_at'],
            $this->tempDb,
            $this->schema
        );

        $qualifiedLogs = $this->tempDb->qualifiedTableName('logs');
        $count = DB::table(DB::raw($qualifiedLogs))->count();

        $this->assertEquals(0, $count);
    }
}
