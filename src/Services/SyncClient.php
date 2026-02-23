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

            throw new \RuntimeException($message);
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

        // Stream to a temp file, then extract meta and write clean SQL to the final path.
        // This avoids rename() which fails on Windows due to file locking.
        $filePath = $destDir . DIRECTORY_SEPARATOR . "{$table}.sql";
        $tempPath = $filePath . '.tmp';

        $response = $this->request()
            ->timeout($this->timeout)
            ->withOptions(['sink' => $tempPath])
            ->get($url, $params);

        if (! $response->successful()) {
            $errorMessage = '';
            if (file_exists($tempPath)) {
                $body = file_get_contents($tempPath);
                $json = json_decode($body, true);
                $errorMessage = isset($json['error']) ? " — {$json['error']}" : ($body ? " — " . substr($body, 0, 500) : '');
            }
            @unlink($tempPath);

            $message = "Failed to download dump for '{$table}': HTTP {$response->status()}{$errorMessage}";

            if ($response->status() === 404) {
                $message .= "\n\nRecommendations:\n - Is the elliptic/backfill package installed on the remote server?\n - Make sure the BACKFILL_TOKEN env variable is set and matches on both ends.\n - Make sure BACKFILL_SERVER_ENABLED=true is set on the remote server's .env file.";
            }

            throw new \RuntimeException($message);
        }

        // The first line of the file is JSON metadata, extract it
        $meta = $this->extractMetaFromDump($tempPath, $filePath);

        // Clean up the temp download
        @unlink($tempPath);

        return [
            'path' => $filePath,
            'meta' => $meta,
        ];
    }

    /**
     * Extract the JSON metadata line from a downloaded dump file,
     * and write clean SQL (without the meta header) to the final destination.
     */
    protected function extractMetaFromDump(string $sourcePath, string $destPath): array
    {
        $handle = fopen($sourcePath, 'r');
        if (! $handle) {
            return [];
        }

        // First line is JSON meta
        $metaLine = fgets($handle);
        // Second line is "-- BEGIN SQL DUMP --"
        $markerLine = fgets($handle);

        $meta = json_decode(trim($metaLine), true) ?? [];

        // Write the remaining SQL content to the final destination
        $destHandle = fopen($destPath, 'w');

        while (($line = fgets($handle)) !== false) {
            fwrite($destHandle, $line);
        }

        fclose($handle);
        fclose($destHandle);

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
