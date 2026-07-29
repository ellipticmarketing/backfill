<?php

use Elliptic\Backfill\Services\SqlDumpTransformer;

it('rewrites import SQL correctly across every stream buffer boundary', function () {
    $sourceSql = <<<'SQL'
INSERT INTO `_backfill_users` (`id`) VALUES (1);
INSERT INTO `_backfill_users` (`id`) VALUES (2);
SQL;

    $expectedSql = <<<'SQL'
SET FOREIGN_KEY_CHECKS=0;
REPLACE INTO `users` (`id`) VALUES (1);
REPLACE INTO `users` (`id`) VALUES (2);
SET FOREIGN_KEY_CHECKS=1;

SQL;

    foreach (range(1, 32) as $bufferBytes) {
        $sourcePath = tempnam(sys_get_temp_dir(), 'backfill-source-');
        $destinationPath = tempnam(sys_get_temp_dir(), 'backfill-import-');

        file_put_contents($sourcePath, $sourceSql);

        try {
            (new SqlDumpTransformer($bufferBytes))->writeImportFile(
                'users',
                $sourcePath,
                $destinationPath,
                true,
            );

            expect(file_get_contents($destinationPath))->toBe($expectedSql);
        } finally {
            @unlink($sourcePath);
            @unlink($destinationPath);
        }
    }
});

it('keeps incomplete replacement prefixes at the end of a dump', function () {
    $sourcePath = tempnam(sys_get_temp_dir(), 'backfill-source-');
    $destinationPath = tempnam(sys_get_temp_dir(), 'backfill-import-');

    file_put_contents(
        $sourcePath,
        "INSERT INTO `_backfill_users` (`id`) VALUES (1);\nSELECT \"INSERT INT\";",
    );

    try {
        (new SqlDumpTransformer(3))->writeImportFile(
            'users',
            $sourcePath,
            $destinationPath,
            false,
        );

        expect(file_get_contents($destinationPath))->toBe(
            "SET FOREIGN_KEY_CHECKS=0;\n"
            ."INSERT INTO `users` (`id`) VALUES (1);\n"
            .'SELECT "INSERT INT";'
            ."\nSET FOREIGN_KEY_CHECKS=1;\n",
        );
    } finally {
        @unlink($sourcePath);
        @unlink($destinationPath);
    }
});
