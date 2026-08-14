<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:see-app-cloudshare'])->group(function (): void {
    Route::livewire('apps/cloudshare', 'intranet-app-cloudshare::apps.cloudshare.index')->name('apps.cloudshare.index');
    Route::livewire('apps/cloudshare/settings/user', 'intranet-app-cloudshare::apps.cloudshare.settings.user')->name('apps.cloudshare.settings.user');
    Route::livewire('apps/cloudshare/info', 'intranet-app-cloudshare::apps.cloudshare.info')->name('apps.cloudshare.info');
});

Route::middleware(['web', 'auth', 'can:manage-app-cloudshare'])->group(function (): void {
    Route::livewire('apps/cloudshare/admin', 'intranet-app-cloudshare::apps.cloudshare.admin.index')->name('apps.cloudshare.admin.index');
});
