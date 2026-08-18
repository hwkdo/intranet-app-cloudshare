<?php

declare(strict_types=1);

use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Hwkdo\IntranetAppCloudshare\Data\AppSettings;
use Hwkdo\IntranetAppCloudshare\Models\IntranetAppCloudshareSettings;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    Permission::findOrCreate('see-app-cloudshare', 'web');

    if (! Schema::hasTable('intranet_app_cloudshare_settings')) {
        Schema::create('intranet_app_cloudshare_settings', function ($table): void {
            $table->id();
            $table->integer('version')->default(1);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }
});

it('fuehrt den purge-command nicht aus wenn automatisches loeschen deaktiviert ist', function (): void {
    IntranetAppCloudshareSettings::query()->delete();
    IntranetAppCloudshareSettings::query()->create([
        'version' => 1,
        'settings' => new AppSettings(autoDeleteExpiredEnabled: false),
    ]);

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldNotReceive('purgeExpiredShares');
    });

    $this->artisan('intranet-app-cloudshare:purge-expired-shares')
        ->expectsOutput('Automatisches Löschen ist deaktiviert.')
        ->assertSuccessful();
});

it('loescht abgelaufene freigaben wenn automatisches loeschen aktiviert ist', function (): void {
    IntranetAppCloudshareSettings::query()->delete();
    IntranetAppCloudshareSettings::query()->create([
        'version' => 1,
        'settings' => new AppSettings(
            autoDeleteExpiredEnabled: true,
            autoDeleteExpiredAfterDays: 14,
        ),
    ]);

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('purgeExpiredShares')
            ->once()
            ->with(14)
            ->andReturn([
                'deleted' => 2,
                'skipped_users' => 1,
                'failed' => 0,
            ]);
    });

    $this->artisan('intranet-app-cloudshare:purge-expired-shares')
        ->expectsOutput('Abgelaufene Freigaben geprüft (Karenz: 14 Tage). Gelöscht: 2, Benutzer ohne Zugriff: 1, Fehler: 0.')
        ->assertSuccessful();
});

it('fuehrt den purge-command mit force auch bei deaktivierter einstellung aus', function (): void {
    IntranetAppCloudshareSettings::query()->delete();
    IntranetAppCloudshareSettings::query()->create([
        'version' => 1,
        'settings' => new AppSettings(
            autoDeleteExpiredEnabled: false,
            autoDeleteExpiredAfterDays: 7,
        ),
    ]);

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('purgeExpiredShares')
            ->once()
            ->with(7)
            ->andReturn([
                'deleted' => 0,
                'skipped_users' => 0,
                'failed' => 1,
            ]);
    });

    $this->artisan('intranet-app-cloudshare:purge-expired-shares', ['--force' => true])
        ->expectsOutput('Abgelaufene Freigaben geprüft (Karenz: 7 Tage). Gelöscht: 0, Benutzer ohne Zugriff: 0, Fehler: 1.')
        ->assertFailed();
});
