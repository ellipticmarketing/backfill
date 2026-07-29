<?php

namespace Elliptic\Backfill\Services;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

class SqlDumpTransformer
{
    public function __construct(protected int $bufferBytes = 1048576)
    {
        if ($this->bufferBytes < 1) {
            throw new InvalidArgumentException('The SQL dump stream buffer must be at least one byte.');
        }
    }

    public function writeImportFile(
        string $table,
        string $sourcePath,
        string $destinationPath,
        bool $isDelta,
    ): void {
        $source = fopen($sourcePath, 'rb');
        if ($source === false) {
            throw new RuntimeException("Unable to read SQL dump from '{$sourcePath}'.");
        }

        $destination = fopen($destinationPath, 'wb');
        if ($destination === false) {
            fclose($source);

            throw new RuntimeException("Unable to create import SQL at '{$destinationPath}'.");
        }

        $replacements = [
            "`_backfill_{$table}`" => "`{$table}`",
        ];

        if ($isDelta) {
            $replacements['INSERT INTO'] = 'REPLACE INTO';
        }

        $deleteDestination = false;

        try {
            $this->writeAll($destination, "SET FOREIGN_KEY_CHECKS=0;\n");

            $pending = '';

            while (! feof($source)) {
                $chunk = fread($source, $this->bufferBytes);

                if ($chunk === false) {
                    throw new RuntimeException("Unable to read SQL dump from '{$sourcePath}'.");
                }

                if ($chunk === '') {
                    continue;
                }

                $pending .= $chunk;
                $this->writeAvailableContent($destination, $pending, $replacements, false);
            }

            $this->writeAvailableContent($destination, $pending, $replacements, true);
            $this->writeAll($destination, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        } catch (Throwable $exception) {
            $deleteDestination = true;

            throw $exception;
        } finally {
            fclose($source);
            fclose($destination);

            if ($deleteDestination) {
                @unlink($destinationPath);
            }
        }
    }

    /**
     * @param  resource  $destination
     * @param  array<string, string>  $replacements
     */
    protected function writeAvailableContent(
        mixed $destination,
        string &$pending,
        array $replacements,
        bool $isEndOfFile,
    ): void {
        while ($pending !== '') {
            $match = $this->findFirstReplacement($pending, $replacements);

            if ($match !== null) {
                $this->writeAll(
                    $destination,
                    substr($pending, 0, $match['position']).$match['replacement'],
                );

                $pending = substr(
                    $pending,
                    $match['position'] + strlen($match['needle']),
                );

                continue;
            }

            if ($isEndOfFile) {
                $this->writeAll($destination, $pending);
                $pending = '';

                return;
            }

            $carryBytes = $this->replacementPrefixAtEnd($pending, array_keys($replacements));
            $writableBytes = strlen($pending) - $carryBytes;

            if ($writableBytes > 0) {
                $this->writeAll($destination, substr($pending, 0, $writableBytes));
            }

            $pending = $carryBytes > 0 ? substr($pending, -$carryBytes) : '';

            return;
        }
    }

    /**
     * @param  array<string, string>  $replacements
     * @return array{position: int, needle: string, replacement: string}|null
     */
    protected function findFirstReplacement(string $content, array $replacements): ?array
    {
        $firstMatch = null;

        foreach ($replacements as $needle => $replacement) {
            $position = strpos($content, $needle);

            if (
                $position !== false
                && ($firstMatch === null || $position < $firstMatch['position'])
            ) {
                $firstMatch = [
                    'position' => $position,
                    'needle' => $needle,
                    'replacement' => $replacement,
                ];
            }
        }

        return $firstMatch;
    }

    /**
     * @param  array<int, string>  $needles
     */
    protected function replacementPrefixAtEnd(string $content, array $needles): int
    {
        $carryBytes = 0;

        foreach ($needles as $needle) {
            $maximumPrefixBytes = min(strlen($content), strlen($needle) - 1);

            for ($prefixBytes = $maximumPrefixBytes; $prefixBytes > $carryBytes; $prefixBytes--) {
                if (
                    substr($content, -$prefixBytes)
                    === substr($needle, 0, $prefixBytes)
                ) {
                    $carryBytes = $prefixBytes;

                    break;
                }
            }
        }

        return $carryBytes;
    }

    /**
     * @param  resource  $destination
     */
    protected function writeAll(mixed $destination, string $content): void
    {
        while ($content !== '') {
            $writtenBytes = fwrite($destination, $content);

            if ($writtenBytes === false || $writtenBytes === 0) {
                throw new RuntimeException('Unable to write the prepared Backfill import SQL.');
            }

            $content = substr($content, $writtenBytes);
        }
    }
}
