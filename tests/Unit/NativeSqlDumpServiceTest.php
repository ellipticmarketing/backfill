<?php

namespace Elliptic\Backfill\Tests\Unit;

use Elliptic\Backfill\Services\ImportService;
use Elliptic\Backfill\Services\NativeSqlDumpService;
use Elliptic\Backfill\Services\TempDatabaseService;
use Elliptic\Backfill\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NativeSqlDumpServiceTest extends TestCase
{
    public function test_it_streams_complete_insert_statements_with_binary_safe_values(): void
    {
        Schema::create('payloads', function ($table) {
            $table->id();
            $table->string('label');
            $table->binary('payload');
            $table->string('optional')->nullable();
            $table->integer('quantity');
        });

        DB::table('payloads')->insert([
            [
                'id' => 1,
                'label' => "O'Reilly\nback\\slash",
                'payload' => "\x00\xff'binary",
                'optional' => null,
                'quantity' => 42,
            ],
            [
                'id' => 2,
                'label' => '',
                'payload' => '',
                'optional' => 'present',
                'quantity' => -7,
            ],
        ]);

        config([
            'backfill.server.temp_strategy' => 'tables',
            'backfill.server.chunk_size' => 1,
        ]);

        $tempDatabase = new TempDatabaseService;
        $tempDatabase->prepare('payloads');

        ob_start();
        (new NativeSqlDumpService)->stream(
            'payloads',
            $tempDatabase,
            ['id', 'label', 'payload', 'optional', 'quantity'],
            ['id'],
        );
        $sql = ob_get_clean();

        expect($sql)
            ->toContain('INSERT INTO `_backfill_payloads` (`id`, `label`, `payload`, `optional`, `quantity`) VALUES')
            ->toContain("(1, X'4f275265696c6c790a6261636b5c736c617368', X'00ff2762696e617279', NULL, 42);")
            ->toContain("(2, '', '', X'70726573656e74', -7);")
            ->and(substr_count($sql, 'INSERT INTO'))->toBe(2);

        $dumpPath = tempnam(sys_get_temp_dir(), 'backfill-native-dump-');
        file_put_contents($dumpPath, $sql);

        $tempDatabase->cleanup('payloads');

        try {
            (new ImportService)->importSqlDump('payloads', $dumpPath, false);

            $rows = DB::table('payloads')->orderBy('id')->get();

            expect($rows)->toHaveCount(2)
                ->and($rows[0]->label)->toBe("O'Reilly\nback\\slash")
                ->and($rows[0]->payload)->toBe("\x00\xff'binary")
                ->and($rows[0]->optional)->toBeNull()
                ->and($rows[0]->quantity)->toBe(42)
                ->and($rows[1]->label)->toBe('')
                ->and($rows[1]->payload)->toBe('')
                ->and($rows[1]->optional)->toBe('present')
                ->and($rows[1]->quantity)->toBe(-7);
        } finally {
            @unlink($dumpPath);
            Schema::dropIfExists('payloads');
        }
    }

    public function test_database_strategy_targets_the_original_table_name(): void
    {
        Schema::create('widgets', function ($table) {
            $table->id();
            $table->string('name');
        });

        DB::table('widgets')->insert([
            ['id' => 1, 'name' => 'Example'],
        ]);

        $tempDatabase = \Mockery::mock(TempDatabaseService::class);
        $tempDatabase->shouldReceive('getStrategy')
            ->once()
            ->andReturn('database');
        $tempDatabase->shouldReceive('queryBuilder')
            ->once()
            ->with('widgets')
            ->andReturn(DB::table('widgets'));

        ob_start();
        (new NativeSqlDumpService)->stream(
            'widgets',
            $tempDatabase,
            ['id', 'name'],
            ['id'],
        );
        $sql = ob_get_clean();

        expect($sql)
            ->toContain("INSERT INTO `widgets` (`id`, `name`) VALUES\n(1, X'4578616d706c65');")
            ->not->toContain('`_backfill_widgets`');

        Schema::dropIfExists('widgets');
    }
}
