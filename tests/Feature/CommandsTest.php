<?php

it('blocks pull on non-allowed environments', function () {
    config(['backfill.client.allowed_environments' => ['local', 'staging']]);
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('backfill:pull')
        ->expectsOutputToContain('only allowed')
        ->assertExitCode(1);
});

it('supports backfill as an alias for backfill:pull', function () {
    config(['backfill.client.allowed_environments' => ['local', 'staging']]);
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('backfill')
        ->expectsOutputToContain('only allowed')
        ->assertExitCode(1);
});

it('shows status with no sync history', function () {
    $this->artisan('backfill:status')
        ->expectsOutputToContain('No sync history found')
        ->assertExitCode(0);
});

it('runs the install command successfully', function () {
    $this->artisan('backfill:install')
        ->expectsOutputToContain('Generated token')
        ->expectsOutputToContain('Environment Setup')
        ->assertExitCode(0);
})->skip(fn () => file_exists(base_path('.gitignore')) || file_exists(base_path('.env')), 'Skipped when .env or .gitignore exists (interactive prompts)');

it('registers the install command', function () {
    $this->artisan('backfill:install --help')
        ->expectsOutputToContain('Generate a sync token')
        ->assertExitCode(0);
});
