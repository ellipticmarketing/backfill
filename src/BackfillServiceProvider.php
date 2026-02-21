<?php

namespace Elliptic\Backfill;

use Elliptic\Backfill\Commands\CleanupCommand;
use Elliptic\Backfill\Commands\InstallCommand;
use Elliptic\Backfill\Commands\PullCommand;
use Elliptic\Backfill\Commands\StatusCommand;
use Elliptic\Backfill\Http\Controllers\DumpController;
use Elliptic\Backfill\Http\Controllers\ManifestController;
use Elliptic\Backfill\Http\Middleware\BackfillAuth;
use Elliptic\Backfill\Services\ImportService;
use Elliptic\Backfill\Services\RowLimiterService;
use Elliptic\Backfill\Services\SanitizationService;
use Elliptic\Backfill\Services\SyncClient;
use Elliptic\Backfill\Services\SyncState;
use Elliptic\Backfill\Services\TempDatabaseService;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class BackfillServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('backfill')
            ->hasConfigFile()
            ->hasCommands([
                PullCommand::class,
                StatusCommand::class,
                CleanupCommand::class,
                InstallCommand::class,
            ]);
    }

    public function packageBooted(): void
    {
        $this->registerRoutes();
        $this->registerBindings();
        $this->registerScheduledCleanup();
    }

    protected function registerScheduledCleanup(): void
    {
        if (! config('backfill.server.enabled')) {
            return;
        }

        $this->app->booted(function () {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
            $schedule->command('backfill:cleanup --force --max-age=60')
                ->hourly()
                ->withoutOverlapping()
                ->runInBackground();
        });
    }

    protected function registerRoutes(): void
    {
        if (! config('backfill.server.enabled')) {
            return;
        }

        $prefix = config('backfill.server.route_prefix', 'api/backfill');
        $middleware = array_merge(['api'], config('backfill.server.middleware', []), [BackfillAuth::class]);

        Route::prefix($prefix)
            ->middleware($middleware)
            ->group(function () {
                Route::get('manifest', ManifestController::class)->name('backfill.manifest');
                Route::get('dump/{table}', DumpController::class)->name('backfill.dump');
            });
    }

    protected function registerBindings(): void
    {
        $this->app->singleton(SanitizationService::class);
        $this->app->singleton(RowLimiterService::class);
        $this->app->singleton(TempDatabaseService::class);
        $this->app->singleton(ImportService::class);
        $this->app->singleton(SyncClient::class);
        $this->app->singleton(SyncState::class);
    }
}
