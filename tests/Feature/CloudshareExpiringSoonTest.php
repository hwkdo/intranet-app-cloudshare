<?php

declare(strict_types=1);

use App\Models\User;
use Hwkdo\IntranetAppBase\Services\DashboardWidgetRegistry;
use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Hwkdo\IntranetAppCloudshare\Data\UserSettings;
use Hwkdo\IntranetAppCloudshare\Support\CloudshareShareExpiration;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    Permission::findOrCreate('see-app-cloudshare', 'web');
    Livewire::withoutLazyLoading();
    Carbon::setTestNow(Carbon::parse('2026-08-18 12:00:00', config('app.timezone')));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('verwendet sieben tage als standard fuer laeuft bald ab', function (): void {
    expect(UserSettings::from([])->expiringSoonDays)->toBe(7)
        ->and(CloudshareShareExpiration::expiringSoonDaysFor(null))->toBe(7);
});

it('erkennt freigaben innerhalb der frist als bald ablaufend', function (): void {
    expect(CloudshareShareExpiration::isExpiringSoon([
        'expiration' => '18.08.2026 23:59 Uhr',
    ], 7))->toBeTrue()
        ->and(CloudshareShareExpiration::isExpiringSoon([
            'expiration' => '25.08.2026 23:59 Uhr',
        ], 7))->toBeTrue()
        ->and(CloudshareShareExpiration::isExpiringSoon([
            'expiration' => '26.08.2026 23:59 Uhr',
        ], 7))->toBeFalse()
        ->and(CloudshareShareExpiration::isExpiringSoon([
            'expiration' => '31.12.2026 23:59 Uhr',
        ], 7))->toBeFalse()
        ->and(CloudshareShareExpiration::isExpiringSoon([
            'expiration' => null,
        ], 7))->toBeFalse()
        ->and(CloudshareShareExpiration::isExpiringSoon([
            'expiration' => '17.08.2026 23:59 Uhr',
        ], 7))->toBeFalse();
});

it('hebt bald ablaufende freigaben in der liste hervor', function (): void {
    $user = User::factory()->create([
        'username' => 'expire.list',
        'vorname' => 'Expire',
        'nachname' => 'List',
        'email' => 'expire.list@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([
            cloudshareSampleShare([
                'name' => 'Bald-Ablauf',
                'id' => 'share-soon',
                'expiration' => '25.08.2026 23:59 Uhr',
            ]),
            cloudshareSampleShare([
                'name' => 'Spaeter-Ablauf',
                'id' => 'share-later',
                'expiration' => '31.12.2026 23:59 Uhr',
            ]),
        ]);
        $mock->shouldReceive('quota')->andReturn(null);
        $mock->shouldReceive('listFiles')->andReturn([]);
    });

    actingAs($user);

    $component = Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->assertSuccessful()
        ->assertSee('Bald-Ablauf')
        ->assertSee('Spaeter-Ablauf')
        ->assertSee('Läuft bald ab');

    $instance = $component->instance();

    expect($instance->shareIsExpiringSoon([
        'expiration' => '25.08.2026 23:59 Uhr',
    ]))->toBeTrue()
        ->and($instance->shareIsExpiringSoon([
            'expiration' => '31.12.2026 23:59 Uhr',
        ]))->toBeFalse();
});

it('beruecksichtigt das user-setting fuer die ablauf-frist', function (): void {
    $user = User::factory()->create([
        'username' => 'expire.setting',
        'vorname' => 'Expire',
        'nachname' => 'Setting',
        'email' => 'expire.setting@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');
    $user->settings = $user->settings->updateAppSettings('cloudshare', [
        'expiringSoonDays' => 3,
    ]);
    $user->save();

    expect(CloudshareShareExpiration::expiringSoonDaysFor($user->fresh()))->toBe(3)
        ->and(CloudshareShareExpiration::isExpiringSoon([
            'expiration' => '25.08.2026 23:59 Uhr',
        ], 3))->toBeFalse()
        ->and(CloudshareShareExpiration::isExpiringSoon([
            'expiration' => '21.08.2026 23:59 Uhr',
        ], 3))->toBeTrue();
});

it('registriert das widget bald ablaufende freigaben am dashboard', function (): void {
    $user = User::factory()->create([
        'username' => 'expire.widget.reg',
        'vorname' => 'Expire',
        'nachname' => 'Widget',
        'email' => 'expire.widget.reg@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $keys = collect(app(DashboardWidgetRegistry::class)->widgetsForMainDashboard($user))
        ->pluck('key')
        ->all();

    expect($keys)->toContain('cloudshare.ablaufende-freigaben');
});

it('zeigt im widget nur bald ablaufende freigaben', function (): void {
    $user = User::factory()->create([
        'username' => 'expire.widget',
        'vorname' => 'Expire',
        'nachname' => 'Widget',
        'email' => 'expire.widget@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([
            cloudshareSampleShare([
                'name' => 'Bald-Ablauf',
                'id' => 'share-soon',
                'expiration' => '20.08.2026 23:59 Uhr',
            ]),
            cloudshareSampleShare([
                'name' => 'Spaeter-Ablauf',
                'id' => 'share-later',
                'expiration' => '31.12.2026 23:59 Uhr',
            ]),
        ]);
    });

    actingAs($user);

    Livewire::test('intranet-app-cloudshare::apps.cloudshare.widgets.ablaufende-freigaben')
        ->assertSuccessful()
        ->assertSee('Bald ablaufende Freigaben')
        ->assertSee('Bald-Ablauf')
        ->assertSee('Gültig bis 20.08.2026 23:59 Uhr')
        ->assertDontSee('Spaeter-Ablauf');
});
