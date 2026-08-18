<?php

declare(strict_types=1);

use Hwkdo\IntranetAppCloudshare\Models\IntranetAppCloudshareSettings;
use Illuminate\Support\Facades\Schedule;

if (app()->runningUnitTests()) {
    return;
}

$hours = IntranetAppCloudshareSettings::resolved()->normalizedAutoDeleteCheckEveryHours();

Schedule::command('intranet-app-cloudshare:purge-expired-shares')
    ->cron('0 */'.$hours.' * * *')
    ->when(fn (): bool => IntranetAppCloudshareSettings::resolved()->autoDeleteExpiredEnabled)
    ->withoutOverlapping()
    ->name('cloudshare-purge-expired-shares');
