<?php

use Elliptic\Backfill\Services\SyncClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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
            ."-- END BACKFILL CHUNK {\"next_cursor\":\"2\",\"complete\":false} --\n",
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
            ."-- END BACKFILL CHUNK {\"next_cursor\":\"3\",\"complete\":true} --\n",
        );

    try {
        $result = (new SyncClient)->downloadTableDump('users', $directory);

        expect(file_get_contents($result['path']))
            ->toBe(
                "INSERT INTO `users` (`id`) VALUES (1), (2);\n"
                ."INSERT INTO `users` (`id`) VALUES (3);\n"
            )
            ->and($result['meta']['complete'])->toBeTrue();

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

it('rejects a chunk without its trailing success marker', function () {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backfill-sync-client-'.uniqid();
    mkdir($directory);

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
        (new SyncClient)->downloadTableDump('users', $directory);
    } finally {
        expect(file_exists($directory.DIRECTORY_SEPARATOR.'users.sql'))->toBeFalse();

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
})->throws(RuntimeException::class, 'success marker');

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

    try {
        $result = (new SyncClient)->downloadTableDump('users', $directory);

        expect(file_get_contents($result['path']))
            ->toBe("INSERT INTO `users` (`id`) VALUES (1);\n");

        Http::assertSentCount(1);
    } finally {
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});
