<?php

namespace Elliptic\Backfill\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
    protected $signature = 'backfill:install';

    protected $description = 'Generate a sync token and display setup instructions for your environment';

    /**
     * Column names that likely contain sensitive data, mapped to sanitization types.
     */
    protected array $sensitiveColumns = [
        'email' => 'email',
        'e_mail' => 'email',
        'email_address' => 'email',
        'password' => 'hash',
        'password_hash' => 'hash',
        'name' => 'name',
        'first_name' => 'name',
        'last_name' => 'name',
        'full_name' => 'name',
        'phone' => 'phone',
        'phone_number' => 'phone',
        'mobile' => 'phone',
        'telephone' => 'phone',
        'cell' => 'phone',
        'address' => 'address',
        'street' => 'address',
        'street_address' => 'address',
        'address_line_1' => 'address',
        'address_line_2' => 'address',
        'ssn' => 'null',
        'social_security' => 'null',
        'tax_id' => 'null',
        'credit_card' => 'null',
        'card_number' => 'null',
        'ip_address' => 'text',
        'last_login_ip' => 'text',
    ];

    public function handle(): int
    {
        $this->newLine();
        $this->components->info('Laravel Backfill — Setup');
        $this->newLine();

        // Step 1: Generate a token
        $token = Str::random(64);

        $this->line("  Generated token:");
        $this->newLine();
        $this->line("  <fg=green>{$token}</>");
        $this->newLine();

        // Offer to write the token to .env
        $envPath = base_path('.env');
        $written = false;

        if (file_exists($envPath)) {
            if ($this->confirm('Add BACKFILL_TOKEN to your .env file?', true)) {
                $this->writeToEnv($envPath, 'BACKFILL_TOKEN', $token);
                $written = true;
                $this->components->info('Token written to .env');
            }
        }

        // Step 2: Interactive Environment Setup
        $this->newLine();
        $this->line('  ┌─────────────────────────────────────────────────────┐');
        $this->line('  │              <fg=yellow>Environment Setup</>                       │');
        $this->line('  └─────────────────────────────────────────────────────┘');
        $this->newLine();

        $envType = $this->choice(
            'Are you setting up Backfill on the Server (production) or Client (local/staging)?',
            ['Server', 'Client'],
            app()->environment('production') ? 0 : 1
        );

        if ($envType === 'Server') {
            $this->setupServer($token, $envPath);
        } else {
            $this->setupClient($envPath);
            // Re-read token from config/env since the user just pasted it in
            $token = env('BACKFILL_TOKEN') ?: config('backfill.auth_token');
        }

        // Step 3: Publish config if it doesn't exist
        $this->newLine();
        $configPath = config_path('backfill.php');

        if (! file_exists($configPath)) {
            if ($this->confirm('Publish the backfill config file now?', true)) {
                $this->callSilently('vendor:publish', ['--tag' => 'backfill-config']);
                $this->components->info('Config file published to config/backfill.php');
            }
        } else {
            $this->components->info('Config file already exists at config/backfill.php');
        }

        $this->newLine();
        $this->line('  Edit <fg=white>config/backfill.php</> to set your sanitization');
        $this->line('  rules, row limits, and excluded tables.');
        $this->newLine();

        // Step 4: Gitignore the state file
        $this->addToGitignore();

        // Step 5: Auto-suggest sanitization rules (client-side only, if source URL is configured)
        if (app()->environment('local', 'staging')) {
            $this->suggestSanitizationRules();
        }

        // Step 6: Connection verification (client-side only)
        if (app()->environment('local', 'staging')) {
            $this->verifyConnection();
        }

        return self::SUCCESS;
    }

    /**
     * Add storage/backfill-state.json to .gitignore if not already there.
     */
    protected function addToGitignore(): void
    {
        $gitignorePath = base_path('.gitignore');

        if (! file_exists($gitignorePath)) {
            return;
        }

        $content = file_get_contents($gitignorePath);

        if (str_contains($content, 'backfill-state.json')) {
            return;
        }

        if ($this->confirm('Add storage/backfill-state.json to .gitignore?', true)) {
            file_put_contents($gitignorePath, rtrim($content) . "\nstorage/backfill-state.json\n");
            $this->components->info('Added to .gitignore');
        }
    }

    /**
     * Suggest sanitization rules by fetching the manifest and scanning column names.
     */
    protected function suggestSanitizationRules(): void
    {
        $sourceUrl = config('backfill.client.source_url');
        $token = config('backfill.auth_token');

        if (! $sourceUrl || ! $token) {
            return;
        }

        if (! $this->confirm('Scan the server for columns that may need sanitization?', true)) {
            return;
        }

        $this->line('  Fetching schema from server...');

        try {
            $prefix = config('backfill.server.route_prefix', 'api/backfill');
            $response = Http::withToken($token)
                ->timeout(30)
                ->get(rtrim($sourceUrl, '/') . "/{$prefix}/manifest");

            if (! $response->successful()) {
                $this->warn("  Could not reach server (HTTP {$response->status()}). Skipping.");

                return;
            }

            $manifest = $response->json();
        } catch (\Throwable $e) {
            $this->warn("  Could not reach server: {$e->getMessage()}");

            return;
        }

        $suggestions = [];
        $tables = $manifest['tables'] ?? [];

        foreach ($tables as $tableName => $tableInfo) {
            $columns = $tableInfo['columns'] ?? [];

            foreach ($columns as $column) {
                $colName = is_array($column) ? ($column['name'] ?? $column) : $column;
                $colNameLower = strtolower($colName);

                if (isset($this->sensitiveColumns[$colNameLower])) {
                    $suggestions[$tableName][$colName] = $this->sensitiveColumns[$colNameLower];
                }
            }
        }

        if (empty($suggestions)) {
            $this->components->info('No obviously sensitive columns detected.');

            return;
        }

        $this->newLine();
        $this->components->warn('Detected columns that may contain sensitive data:');
        $this->newLine();

        $tableData = [];
        foreach ($suggestions as $table => $columns) {
            foreach ($columns as $column => $type) {
                $tableData[] = [$table, $column, $type];
            }
        }

        $this->table(['Table', 'Column', 'Suggested Type'], $tableData);

        if ($this->confirm('Write these suggestions to config/backfill.php?')) {
            $this->writeSanitizationConfig($suggestions);
            $this->components->info('Sanitization rules written to config/backfill.php');
            $this->line('  <fg=gray>Review and adjust the rules, especially exclude patterns.</>');
        } else {
            $this->line('  <fg=gray>You can add these manually to config/backfill.php later.</>');
        }
    }

    /**
     * Write suggested sanitization rules into the config file.
     */
    protected function writeSanitizationConfig(array $suggestions): void
    {
        $configPath = config_path('backfill.php');

        if (! file_exists($configPath)) {
            return;
        }

        $content = file_get_contents($configPath);

        // Build the PHP array string for the rules
        $rules = [];
        foreach ($suggestions as $table => $columns) {
            $columnRules = [];
            foreach ($columns as $column => $type) {
                $columnRules[] = "            '{$column}' => ['type' => '{$type}'],";
            }
            $rules[] = "        '{$table}' => [\n" . implode("\n", $columnRules) . "\n        ],";
        }

        $rulesString = implode("\n", $rules);

        // Replace the empty sanitize block with the generated rules
        $content = preg_replace(
            "/('sanitize'\s*=>\s*\[)\s*(\n\s*\/\/.*\n)*\s*(\],)/s",
            "'sanitize' => [\n{$rulesString}\n    ],",
            $content
        );

        file_put_contents($configPath, $content);
    }

    /**
     * Test the connection to the production server.
     */
    protected function verifyConnection(): void
    {
        $sourceUrl = config('backfill.client.source_url');
        $token = config('backfill.auth_token');

        if (! $sourceUrl || ! $token) {
            return;
        }

        if (! $this->confirm('Test the connection to the server?', true)) {
            return;
        }

        $this->line('  Connecting to <fg=white>' . $sourceUrl . '</>...');

        try {
            $prefix = config('backfill.server.route_prefix', 'api/backfill');
            $response = Http::withToken($token)
                ->timeout(15)
                ->get(rtrim($sourceUrl, '/') . "/{$prefix}/manifest");

            if ($response->successful()) {
                $manifest = $response->json();
                $tableCount = count($manifest['table_order'] ?? []);
                $this->newLine();
                $this->components->info("Connection successful! Server has {$tableCount} syncable tables.");
            } elseif ($response->status() === 401) {
                $this->newLine();
                $this->components->error('Authentication failed — the token on the server does not match.');
            } elseif ($response->status() === 404) {
                $this->newLine();
                $this->components->error('Endpoint not found — is BACKFILL_SERVER_ENABLED=true on the server?');
            } else {
                $this->newLine();
                $this->components->error("Server returned HTTP {$response->status()}");
            }
        } catch (\Throwable $e) {
            $this->newLine();
            $this->components->error("Could not connect: {$e->getMessage()}");
            $this->line('  <fg=gray>Check that the source URL is correct and the server is running.</>');
        }
    }

    protected function setupServer(string $token, string $envPath): void
    {
        $this->components->twoColumnDetail('<fg=yellow>Server (Production)</>', 'This is the data source');
        $this->newLine();

        $this->line('  Make sure these are in your <fg=white>.env</> file:');
        $this->newLine();
        $this->line("  <fg=green>BACKFILL_TOKEN={$token}</>");
        $this->line('  <fg=green>BACKFILL_SERVER_ENABLED=true</>');
        $this->newLine();

        if (file_exists($envPath)) {
            $this->writeToEnv($envPath, 'BACKFILL_SERVER_ENABLED', 'true');
            $this->components->info('BACKFILL_SERVER_ENABLED=true written to .env');
        }

        $this->line('  <fg=gray>Optional — if your DB user cannot create databases:</>');
        $this->newLine();
        $this->line('  <fg=green>BACKFILL_TEMP_USERNAME=privileged_user</>');
        $this->line('  <fg=green>BACKFILL_TEMP_PASSWORD=secret</>');
        $this->newLine();

        $this->line('  <fg=gray>Or use the temp tables strategy instead:</>');
        $this->newLine();
        $this->line('  <fg=green>BACKFILL_TEMP_STRATEGY=tables</>');
    }

    protected function setupClient(string $envPath): void
    {
        $this->components->twoColumnDetail('<fg=yellow>Client (Local/Staging)</>', 'This pulls data from production');
        $this->newLine();

        if (! file_exists($envPath)) {
            $this->components->error('No .env file found. You will need to configure manually.');
            return;
        }

        $sourceUrl = $this->ask('What is the production URL? (e.g. https://myapp.com)');
        $serverToken = $this->ask('Paste the BACKFILL_TOKEN generated on the server');

        if ($sourceUrl) {
            $this->writeToEnv($envPath, 'BACKFILL_SOURCE_URL', trim($sourceUrl));
        }

        if ($serverToken) {
            $this->writeToEnv($envPath, 'BACKFILL_TOKEN', trim($serverToken));
            // Update the runtime config so subsequent steps (like connection testing) use the new token
            config(['backfill.auth_token' => trim($serverToken)]);
        }
        
        if ($sourceUrl) {
            config(['backfill.client.source_url' => trim($sourceUrl)]);
        }

        $this->components->info('Client configuration written to .env');
        $this->newLine();

        $this->line('  You can now run:');
        $this->newLine();
        $this->line('  <fg=white>php artisan backfill:pull --full</>     First sync');
        $this->line('  <fg=white>php artisan backfill:pull</>            Incremental sync');
    }

    /**
     * Write or update a key in the .env file.
     */
    protected function writeToEnv(string $path, string $key, string $value): void
    {
        $content = file_get_contents($path);

        if (str_contains($content, "{$key}=")) {
            $content = preg_replace(
                "/^{$key}=.*/m",
                "{$key}={$value}",
                $content
            );
        } else {
            $content .= "\n{$key}={$value}\n";
        }

        file_put_contents($path, $content);
    }
}
