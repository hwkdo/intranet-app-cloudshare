<?php

declare(strict_types=1);

use Hwkdo\IntranetAppCloudshare\Http\Controllers\Api\MeController;
use Hwkdo\IntranetAppCloudshare\Http\Controllers\Api\ShareBitwardenSendController;
use Hwkdo\IntranetAppCloudshare\Http\Controllers\Api\ShareComposeController;
use Hwkdo\IntranetAppCloudshare\Http\Controllers\Api\ShareController;
use Hwkdo\IntranetAppCloudshare\Http\Controllers\Api\ShareFileController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::prefix('api/cloudshare')
    ->middleware(['auth:api', 'throttle:60,1', SubstituteBindings::class])
    ->group(function (): void {
        Route::get('/me', MeController::class)->name('api.cloudshare.me');
        Route::get('/shares', [ShareController::class, 'index'])->name('api.cloudshare.shares.index');
        Route::post('/shares', [ShareController::class, 'store'])->name('api.cloudshare.shares.store');
        Route::get('/shares/{share}/files', [ShareFileController::class, 'index'])->name('api.cloudshare.shares.files.index');
        Route::post('/shares/{share}/files', [ShareFileController::class, 'store'])->name('api.cloudshare.shares.files.store');
        Route::post('/shares/{share}/compose-html', [ShareComposeController::class, 'store'])->name('api.cloudshare.shares.compose-html');
        Route::post('/shares/{share}/bitwarden-send', [ShareBitwardenSendController::class, 'store'])->name('api.cloudshare.shares.bitwarden-send');
    });
