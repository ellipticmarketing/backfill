<?php

use Elliptic\Backfill\Services\ServerRequirementsService;

function requirementsChecker(bool $processExecutionAvailable, ?string $mysqldumpPath): ServerRequirementsService
{
    return new class($processExecutionAvailable, $mysqldumpPath) extends ServerRequirementsService
    {
        public function __construct(
            private readonly bool $processExecutionAvailable,
            private readonly ?string $mysqldumpPath,
        ) {}

        protected function isProcessExecutionAvailable(): bool
        {
            return $this->processExecutionAvailable;
        }

        protected function findMysqldump(): ?string
        {
            return $this->mysqldumpPath;
        }
    };
}

it('uses the native fallback when proc open is disabled', function () {
    expect(requirementsChecker(false, '/usr/bin/mysqldump')->ensureRequirementsAreMet())
        ->toBeNull();
});

it('uses the native fallback when mysqldump is unavailable', function () {
    expect(requirementsChecker(true, null)->ensureRequirementsAreMet())
        ->toBeNull();
});

it('accepts servers with process execution and mysqldump', function () {
    expect(requirementsChecker(true, '/usr/bin/mysqldump')->ensureRequirementsAreMet())
        ->toBe('/usr/bin/mysqldump');
});
