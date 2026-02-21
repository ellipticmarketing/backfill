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
    public function getManifest(): array
    {
        $response = $this->request()
            ->get($this->url('manifest'));

        if (! $response->successful()) {
            throw new RuntimeException(
                "Failed to fetch manifest: HTTP {$response->status()} — {$response->body()}"
            );
        }

        return $response->json();
    }

    /**
     * Download the SQL dump for a table and save it to a local file.
     * Returns an array with ['path' => string, 'meta' => array].
     */
    public function downloadTableDump(string $table, string $destDir, ?string $after = null): array
    {
        $params = [];
        if ($after) {
            $params['after'] = $after;
        }

        $url = $this->url("dump/{$table}");

        // Stream the response directly to a temp file to keep memory flat
        $filePath = $destDir . DIRECTORY_SEPARATOR . "{$table}.sql";

        $response = $this->request()
            ->timeout($this->timeout)
            ->withOptions(['sink' => $filePath])
            ->get($url, $params);

        if (! $response->successful()) {
            @unlink($filePath);

            throw new RuntimeException(
                "Failed to download dump for '{$table}': HTTP {$response->status()}"
            );
        }

        // The first line of the file is JSON metadata, extract it
        $meta = $this->extractMetaFromDump($filePath);

        return [
            'path' => $filePath,
            'meta' => $meta,
        ];
    }

    /**
     * Extract the JSON metadata line from the top of a dump file,
     * then rewrite the file without it (clean SQL only).
     */
    protected function extractMetaFromDump(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if (! $handle) {
            return [];
        }

        // First line is JSON meta
        $metaLine = fgets($handle);
        // Second line is "-- BEGIN SQL DUMP --"
        $markerLine = fgets($handle);

        $meta = json_decode(trim($metaLine), true) ?? [];

        // Rewrite the file without the first two lines
        $tempPath = $filePath . '.tmp';
        $tempHandle = fopen($tempPath, 'w');

        while (($line = fgets($handle)) !== false) {
            fwrite($tempHandle, $line);
        }

        fclose($handle);
        fclose($tempHandle);

        // Replace original with cleaned version
        rename($tempPath, $filePath);

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
