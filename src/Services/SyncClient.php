<?php

namespace Elliptic\Backfill\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SyncClient
{
    protected string $baseUrl;

    protected string $token;

    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('backfill.client.source_url', ''), '/');
        $this->token = config('backfill.auth_token', '');
        $this->timeout = config('backfill.client.timeout', 300);
    }

    /**
     * Fetch the manifest from the server.
     */
    public function getManifest(?string $after = null): array
    {
        $params = [];
        if ($after) {
            $params['after'] = $after;
        }

        $response = $this->request()
            ->get($this->url('manifest'), $params);

        if (! $response->successful()) {
            $url = $this->url('manifest');
            $body = $response->json('error') ?? $response->body();
            // Since HTML bodies can be huge, limit the text if it's not a parsed error
            if (! is_string($body)) {
                $body = json_encode($body);
            } elseif (! $response->json('error')) {
                $body = substr($body, 0, 500);
            }

            $message = "Failed to fetch manifest from [{$url}]: HTTP {$response->status()} — {$body}";

            if ($response->status() === 404) {
                $message .= "\n\nRecommendations:\n - Is the elliptic/backfill package installed on the remote server?\n - Make sure the BACKFILL_TOKEN env variable is set and matches on both ends.\n - Make sure BACKFILL_SERVER_ENABLED=true is set on the remote server's .env file.";
            }

            throw new RuntimeException($message);
        }

        return $response->json();
    }

    /**
     * Download the SQL dump for a table and save it to a local file.
     * Returns an array with ['path' => string, 'meta' => array].
     */
    public function downloadTableDump(string $table, string $destDir, ?string $after = null): array
    {
        $params = ['chunked' => 1];
        if ($after) {
            $params['after'] = $after;
        }

        $url = $this->url("dump/{$table}");
        $filePath = $destDir.DIRECTORY_SEPARATOR."{$table}.sql";
        $tempPath = $filePath.'.tmp';
        $buildPath = $filePath.'.part';
        $append = false;
        $previousCursor = null;

        @unlink($tempPath);
        @unlink($buildPath);

        do {
            $response = $this->request()
                ->timeout($this->timeout)
                ->withOptions(['sink' => $tempPath])
                ->get($url, $params);

            try {
                if (! $response->successful()) {
                    $errorMessage = '';
                    if (file_exists($tempPath)) {
                        $body = file_get_contents($tempPath);
                        $json = json_decode($body, true);
                        $errorMessage = isset($json['error']) ? " — {$json['error']}" : ($body ? ' — '.substr($body, 0, 500) : '');
                    }

                    $message = "Failed to download dump for '{$table}': HTTP {$response->status()}{$errorMessage}";

                    if ($response->status() === 404) {
                        $message .= "\n\nRecommendations:\n - Is the elliptic/backfill package installed on the remote server?\n - Make sure the BACKFILL_TOKEN env variable is set and matches on both ends.\n - Make sure BACKFILL_SERVER_ENABLED=true is set on the remote server's .env file.";
                    }

                    throw new RuntimeException($message);
                }

                $meta = $this->extractMetaFromDump($tempPath, $buildPath, $append);
            } finally {
                $response->close();
                @unlink($tempPath);
            }

            if (! ($meta['chunked'] ?? false) || ($meta['complete'] ?? false)) {
                break;
            }

            $nextCursor = $meta['next_cursor'] ?? null;
            $highWater = $meta['high_water'] ?? null;

            if (
                $nextCursor === null
                || $highWater === null
                || (string) $nextCursor === $previousCursor
            ) {
                @unlink($buildPath);

                throw new RuntimeException(
                    "Backfill received invalid chunk progress metadata for '{$table}'."
                );
            }

            $previousCursor = (string) $nextCursor;
            $params['cursor'] = $previousCursor;
            $params['high_water'] = (string) $highWater;
            $append = true;
        } while (true);

        if (! @rename($buildPath, $filePath)) {
            if (! copy($buildPath, $filePath)) {
                throw new RuntimeException("Unable to publish Backfill dump to '{$filePath}'.");
            }

            @unlink($buildPath);
        }

        return [
            'path' => $filePath,
            'meta' => $meta,
        ];
    }

    /**
     * Extract the JSON metadata line from a downloaded dump file,
     * and write clean SQL (without the meta header) to the final destination.
     */
    protected function extractMetaFromDump(
        string $sourcePath,
        string $destPath,
        bool $append = false,
    ): array {
        $handle = fopen($sourcePath, 'r');
        if (! $handle) {
            throw new RuntimeException("Unable to read Backfill dump from '{$sourcePath}'.");
        }

        // First line is JSON meta
        $metaLine = fgets($handle);
        // Second line is "-- BEGIN SQL DUMP --"
        $markerLine = fgets($handle);

        $meta = json_decode(trim($metaLine), true);

        if (! is_array($meta) || trim((string) $markerLine) !== '-- BEGIN SQL DUMP --') {
            fclose($handle);

            throw new RuntimeException('Downloaded response does not contain valid Backfill metadata and SQL dump content.');
        }

        $isChunked = (bool) ($meta['chunked'] ?? false);
        $writePath = $isChunked ? $destPath.'.chunk' : $destPath;
        $destHandle = fopen($writePath, $isChunked ? 'w' : ($append ? 'a' : 'w'));
        $chunkResult = null;

        while (($line = fgets($handle)) !== false) {
            $trimmedLine = ltrim($line);

            if (
                str_starts_with(strtolower($trimmedLine), '<!doctype html')
                || str_starts_with(strtolower($trimmedLine), '<html')
            ) {
                fclose($handle);
                fclose($destHandle);
                @unlink($writePath);
                @unlink($destPath);

                throw new RuntimeException(
                    'Downloaded Backfill dump contains a server error response. Check the production Laravel log.'
                );
            }

            if (str_contains($line, '-- DUMP ERROR:')) {
                fclose($handle);
                fclose($destHandle);
                @unlink($writePath);
                @unlink($destPath);

                throw new RuntimeException(
                    'The production dump failed while generating the Backfill SQL. Check the production Laravel log.'
                );
            }

            if (preg_match('/^-- END BACKFILL CHUNK (.+) --\s*$/', trim($line), $matches)) {
                $chunkResult = json_decode($matches[1], true);

                continue;
            }

            if ($chunkResult !== null && trim($line) !== '') {
                fclose($handle);
                fclose($destHandle);
                @unlink($writePath);
                @unlink($destPath);

                throw new RuntimeException(
                    'Downloaded Backfill chunk contains data after its success marker.'
                );
            }

            fwrite($destHandle, $line);
        }

        fclose($handle);
        fclose($destHandle);

        if ($isChunked) {
            if (! is_array($chunkResult)) {
                @unlink($writePath);
                @unlink($destPath);

                throw new RuntimeException(
                    'Downloaded Backfill chunk is missing its trailing success marker.'
                );
            }

            $chunkHandle = fopen($writePath, 'r');
            $destHandle = fopen($destPath, $append ? 'a' : 'w');
            stream_copy_to_stream($chunkHandle, $destHandle);
            fclose($chunkHandle);
            fclose($destHandle);
            @unlink($writePath);

            $meta = array_merge($meta, $chunkResult);
        }

        return $meta;
    }

    /**
     * Build a configured HTTP client.
     */
    protected function request(): PendingRequest
    {
        if (empty($this->baseUrl)) {
            throw new RuntimeException(
                'Backfill source URL is not configured. Set BACKFILL_SOURCE_URL in your .env file.'
            );
        }

        if (empty($this->token)) {
            throw new RuntimeException(
                'Backfill auth token is not configured. Set BACKFILL_TOKEN in your .env file.'
            );
        }

        return Http::withToken($this->token)
            ->timeout($this->timeout)
            ->retry(3, 1000, function (\Exception $e) {
                return $e instanceof ConnectionException;
            });
    }

    /**
     * Build the full URL for an endpoint.
     */
    protected function url(string $path): string
    {
        $prefix = config('backfill.server.route_prefix', 'api/backfill');

        return "{$this->baseUrl}/{$prefix}/{$path}";
    }
}
