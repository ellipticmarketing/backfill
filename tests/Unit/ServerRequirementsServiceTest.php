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

it('rejects servers where proc open is disabled', function () {
    requirementsChecker(false, '/usr/bin/mysqldump')->ensureRequirementsAreMet();
})->throws(
    RuntimeException::class,
    'The PHP proc_open function is disabled'
);

it('rejects servers without an executable mysqldump binary', function () {
    requirementsChecker(true, null)->ensureRequirementsAreMet();
})->throws(
    RuntimeException::class,
    'The mysqldump executable was not found'
);

it('accepts servers with process execution and mysqldump', function () {
    requirementsChecker(true, '/usr/bin/mysqldump')->ensureRequirementsAreMet();

    expect(true)->toBeTrue();
});
