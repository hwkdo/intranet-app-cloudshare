<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare;

use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Hwkdo\IntranetAppCloudshare\Services\CloudshareService;
use Livewire\Livewire;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class IntranetAppCloudshareServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('intranet-app-cloudshare')
            ->hasConfigFile()
            ->hasViews()
            ->discoversMigrations();
    }

    public function packageRegistered(): void
    {
        $this->app->bind(CloudshareServiceInterface::class, CloudshareService::class);
    }

    public function boot(): void
    {
        parent::boot();

        Livewire::addNamespace(
            namespace: 'intranet-app-cloudshare',
            viewPath: __DIR__.'/../resources/views/livewire',
            classNamespace: 'Hwkdo\IntranetAppCloudshare\Livewire',
            classPath: __DIR__.'/Livewire',
            classViewPath: __DIR__.'/../resources/views/livewire',
        );

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }
}
