<?php

namespace Elliptic\Backfill\Services;

use Symfony\Component\Process\ExecutableFinder;

class ServerRequirementsService
{
    /**
     * Resolve the mysqldump fast path when process execution is available.
     *
     * A null result instructs the dump controller to use the PHP-native
     * streaming fallback.
     */
    public function ensureRequirementsAreMet(): ?string
    {
        if (! $this->isProcessExecutionAvailable()) {
            return null;
        }

        return $this->findMysqldump();
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
