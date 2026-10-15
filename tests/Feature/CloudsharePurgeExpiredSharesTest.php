<?php

declare(strict_types=1);

use Hwkdo\IntranetAppBase\Models\BackgroundActivityConfig;
use Hwkdo\IntranetAppBase\Services\BackgroundActivityCatalog;
use Hwkdo\IntranetAppBase\Services\BackgroundActivityConfigStore;
use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
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
    persistCloudsharePurge(false, 30);

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldNotReceive('purgeExpiredShares');
    });

    $this->artisan('intranet-app-cloudshare:purge-expired-shares')
        ->expectsOutput('Automatisches Löschen ist deaktiviert.')
        ->assertSuccessful();
});

it('loescht abgelaufene freigaben wenn automatisches loeschen aktiviert ist', function (): void {
    persistCloudsharePurge(true, 14);

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
    persistCloudsharePurge(false, 7);

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

function persistCloudsharePurge(bool $enabled, int $afterDays): void
{
    if (! Schema::hasTable('intranet_background_activity_configs')) {
        Schema::create('intranet_background_activity_configs', function ($table): void {
            $table->id();
            $table->string('activity_key');
            $table->boolean('enabled')->default(true);
            $table->string('cadence')->nullable();
            $table->unsignedSmallInteger('every')->default(1);
            $table->unsignedSmallInteger('offset')->default(0);
            $table->string('at', 5)->nullable();
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->json('parameters')->nullable();
            $table->timestamps();
        });
    }

    $definition = app(BackgroundActivityCatalog::class)->find('cloudshare.purge-expired-shares');

    expect($definition)->not->toBeNull();

    app(BackgroundActivityConfigStore::class)->save($definition, [
        'enabled' => $enabled,
        'cadence' => 'every_n_hours',
        'every' => 24,
        'offset' => 0,
        'at' => null,
        'day_of_week' => null,
        'parameters' => ['after_days' => $afterDays],
    ]);

    expect(BackgroundActivityConfig::query()->where('activity_key', 'cloudshare.purge-expired-shares')->exists())->toBeTrue();
}
