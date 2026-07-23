<?php

namespace Elliptic\Backfill\Services;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

class ServerRequirementsService
{
    public function ensureRequirementsAreMet(): string
    {
        if (! $this->isProcessExecutionAvailable()) {
            throw new RuntimeException(
                'Backfill server requirement not met: The PHP proc_open function is disabled. '
                .'Enable proc_open for the PHP-FPM/web runtime before using Backfill.'
            );
        }

        $mysqldumpPath = $this->findMysqldump();

        if ($mysqldumpPath === null) {
            throw new RuntimeException(
                'Backfill server requirement not met: The mysqldump executable was not found. '
                .'Install a MySQL or MariaDB client and ensure mysqldump is available in the PHP-FPM/web PATH.'
            );
        }

        return $mysqldumpPath;
    }

    protected function isProcessExecutionAvailable(): bool
    {
        return function_exists('proc_open');
    }

    protected function findMysqldump(): ?string
    {
        return (new ExecutableFinder)->find('mysqldump');
    }
}
