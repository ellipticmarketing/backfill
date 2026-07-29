<?php

use Elliptic\Backfill\Services\SyncClient;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('preserves the original download method signature for subclasses', function () {
    $client = new class extends SyncClient
    {
        public function downloadTableDump(
            string $table,
            string $destDir,
            ?string $after = null,
        ): array {
            return compact('table', 'destDir', 'after');
        }
    };

    expect($client->downloadTableDump('users', 'dumps', '2026-07-29'))
        ->toBe([
            'table' => 'users',
            'destDir' => 'dumps',
            'after' => '2026-07-29',
        ])
        ->and($client->downloadTableDumpWithProgress(
            'orders',
            'custom-dumps',
            null,
            fn () => null,
        ))->toBe([
            'table' => 'orders',
            'destDir' => 'custom-dumps',
            'after' => null,
        ]);
});

it('downloads a table through bounded HTTP chunks and concatenates them once', function () {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backfill-sync-client-'.uniqid();
    mkdir($directory);

    Http::preventStrayRequests();
    Http::fakeSequence()
        ->push(
            json_encode([
                'primary_key' => ['id'],
                'has_timestamps' => true,
                'chunked' => true,
                'high_water' => '3',
            ])."\n"
            ."-- BEGIN SQL DUMP --\n"
            ."INSERT INTO `users` (`id`) VALUES (1), (2);\n"
            ."-- END BACKFILL CHUNK {\"next_cursor\":\"2\",\"complete\":false,\"chunk_rows\":2} --\n",
        )
        ->push(
            json_encode([
                'primary_key' => ['id'],
                'has_timestamps' => true,
                'chunked' => true,
                'high_water' => '3',
            ])."\n"
            ."-- BEGIN SQL DUMP --\n"
            ."INSERT INTO `users` (`id`) VALUES (3);\n"
            ."-- END BACKFILL CHUNK {\"next_cursor\":\"3\",\"complete\":true,\"chunk_rows\":1} --\n",
        );

    $progressUpdates = [];

    try {
        $result = (new SyncClient)->downloadTableDumpWithProgress(
            'users',
            $directory,
            null,
            function (array $progress) use (&$progressUpdates): void {
                $progressUpdates[] = $progress;
            },
        );

        expect(file_get_contents($result['path']))
            ->toBe(
                "INSERT INTO `users` (`id`) VALUES (1), (2);\n"
                ."INSERT INTO `users` (`id`) VALUES (3);\n"
            )
            ->and($result['meta']['complete'])->toBeTrue()
            ->and($progressUpdates)->toHaveCount(2)
            ->and($progressUpdates[0])->toMatchArray([
                'table' => 'users',
                'chunk' => 1,
                'chunk_rows' => 2,
                'downloaded_rows' => 2,
                'complete' => false,
            ])
            ->and($progressUpdates[1])->toMatchArray([
                'table' => 'users',
                'chunk' => 2,
                'chunk_rows' => 1,
                'downloaded_rows' => 3,
                'complete' => true,
            ])
            ->and($progressUpdates[1]['downloaded_bytes'])
            ->toBe(strlen(file_get_contents($result['path'])));

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['chunked'] ?? null) === '1'
                && ! isset($query['cursor'])
                && ! isset($query['high_water']);
        });
        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['chunked'] ?? null) === '1'
                && ($query['cursor'] ?? null) === '2'
                && ($query['high_water'] ?? null) === '3';
        });
    } finally {
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

it('closes each chunk response before reusing its temporary download path', function () {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backfill-sync-client-'.uniqid();
    mkdir($directory);

    $firstChunkBody = Utils::streamFor(
        json_encode([
            'primary_key' => ['id'],
            'has_timestamps' => true,
            'chunked' => true,
            'high_water' => '2',
        ])."\n"
        ."-- BEGIN SQL DUMP --\n"
        ."INSERT INTO `users` (`id`) VALUES (1);\n"
        ."-- END BACKFILL CHUNK {\"next_cursor\":\"1\",\"complete\":false} --\n",
    );
    $requestCount = 0;

    Http::preventStrayRequests();
    Http::fake(function () use ($firstChunkBody, &$requestCount) {
        $requestCount++;

        if ($requestCount === 1) {
            return Create::promiseFor(new Psr7Response(200, [], $firstChunkBody));
        }

        expect($firstChunkBody->isReadable())->toBeFalse();

        return Http::response(
            json_encode([
                'primary_key' => ['id'],
                'has_timestamps' => true,
                'chunked' => true,
                'high_water' => '2',
            ])."\n"
            ."-- BEGIN SQL DUMP --\n"
            ."INSERT INTO `users` (`id`) VALUES (2);\n"
            ."-- END BACKFILL CHUNK {\"next_cursor\":\"2\",\"complete\":true} --\n",
        );
    });

    try {
        $result = (new SyncClient)->downloadTableDump('users', $directory);

        expect(file_get_contents($result['path']))
            ->toBe(
                "INSERT INTO `users` (`id`) VALUES (1);\n"
                ."INSERT INTO `users` (`id`) VALUES (2);\n"
            )
            ->and($requestCount)->toBe(2);
    } finally {
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

it('rejects a chunk without its trailing success marker', function () {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backfill-sync-client-'.uniqid();
    mkdir($directory);
    $progressUpdates = [];

    Http::preventStrayRequests();
    Http::fake([
        '*' => Http::response(
            json_encode([
                'primary_key' => ['id'],
                'has_timestamps' => true,
                'chunked' => true,
                'high_water' => '3',
            ])."\n"
            ."-- BEGIN SQL DUMP --\n"
            .'INSERT INTO `users` (`id`) VALUES (1',
        ),
    ]);

    try {
        expect(fn () => (new SyncClient)->downloadTableDumpWithProgress(
            'users',
            $directory,
            null,
            function (array $progress) use (&$progressUpdates): void {
                $progressUpdates[] = $progress;
            },
        ))->toThrow(RuntimeException::class, 'success marker');
    } finally {
        expect(file_exists($directory.DIRECTORY_SEPARATOR.'users.sql'))->toBeFalse()
            ->and($progressUpdates)->toBe([]);

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

it('does not report progress for invalid chunk cursor metadata', function () {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backfill-sync-client-'.uniqid();
    mkdir($directory);
    $progressUpdates = [];

    Http::preventStrayRequests();
    Http::fake([
        '*' => Http::response(
            json_encode([
                'primary_key' => ['id'],
                'has_timestamps' => true,
                'chunked' => true,
                'high_water' => '3',
            ])."\n"
            ."-- BEGIN SQL DUMP --\n"
            ."INSERT INTO `users` (`id`) VALUES (1);\n"
            ."-- END BACKFILL CHUNK {\"complete\":false,\"chunk_rows\":1} --\n",
        ),
    ]);

    try {
        expect(fn () => (new SyncClient)->downloadTableDumpWithProgress(
            'users',
            $directory,
            null,
            function (array $progress) use (&$progressUpdates): void {
                $progressUpdates[] = $progress;
            },
        ))->toThrow(RuntimeException::class, 'invalid chunk progress metadata');

        expect($progressUpdates)->toBe([]);
    } finally {
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

it('accepts a complete legacy response from an older server', function () {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backfill-sync-client-'.uniqid();
    mkdir($directory);

    Http::preventStrayRequests();
    Http::fake([
        '*' => Http::response(
            json_encode([
                'primary_key' => ['id'],
                'has_timestamps' => true,
            ])."\n"
            ."-- BEGIN SQL DUMP --\n"
            ."INSERT INTO `users` (`id`) VALUES (1);\n",
        ),
    ]);

    $progressUpdates = [];

    try {
        $result = (new SyncClient)->downloadTableDumpWithProgress(
            'users',
            $directory,
            null,
            function (array $progress) use (&$progressUpdates): void {
                $progressUpdates[] = $progress;
            },
        );

        expect(file_get_contents($result['path']))
            ->toBe("INSERT INTO `users` (`id`) VALUES (1);\n")
            ->and($progressUpdates)->toHaveCount(1)
            ->and($progressUpdates[0])->toMatchArray([
                'chunk' => 1,
                'chunk_rows' => null,
                'downloaded_rows' => null,
                'complete' => true,
            ]);

        Http::assertSentCount(1);
    } finally {
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});
