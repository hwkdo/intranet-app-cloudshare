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

it('erkennt bereits abgelaufene freigaben', function (): void {
    expect(CloudshareShareExpiration::isExpired([
        'expiration' => '17.08.2026 23:59 Uhr',
    ]))->toBeTrue()
        ->and(CloudshareShareExpiration::isExpired([
            'expiration' => '18.08.2026 23:59 Uhr',
        ]))->toBeFalse()
        ->and(CloudshareShareExpiration::isExpired([
            'expiration' => '25.08.2026 23:59 Uhr',
        ]))->toBeFalse()
        ->and(CloudshareShareExpiration::isExpired([
            'expiration' => null,
        ]))->toBeFalse()
        ->and(CloudshareShareExpiration::needsExpirationAttention([
            'expiration' => '17.08.2026 23:59 Uhr',
        ], 7))->toBeTrue()
        ->and(CloudshareShareExpiration::remainingDaysLabel(-1))->toBe('seit 1 Tag abgelaufen')
        ->and(CloudshareShareExpiration::remainingDaysLabel(-3))->toBe('seit 3 Tagen abgelaufen');
});

it('hebt bald ablaufende und abgelaufene freigaben in der liste hervor', function (): void {
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
                'name' => 'Alt-Ordner',
                'id' => 'share-expired',
                'expiration' => '17.08.2026 23:59 Uhr',
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
        ->assertSee('Alt-Ordner')
        ->assertSee('Spaeter-Ablauf')
        ->assertSee('Läuft bald ab')
        ->assertSee('Abgelaufen')
        ->assertSee('Gültigkeit verlängern');

    $instance = $component->instance();

    expect($instance->shareIsExpiringSoon([
        'expiration' => '25.08.2026 23:59 Uhr',
    ]))->toBeTrue()
        ->and($instance->shareIsExpired([
            'expiration' => '17.08.2026 23:59 Uhr',
        ]))->toBeTrue()
        ->and($instance->shareCanExtendExpiration([
            'expiration' => '31.12.2026 23:59 Uhr',
        ]))->toBeFalse()
        ->and($instance->shareCanExtendExpiration([
            'expiration' => '17.08.2026 23:59 Uhr',
        ]))->toBeTrue();
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

it('zeigt im widget bald ablaufende und abgelaufene freigaben', function (): void {
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
                'name' => 'Alt-Ordner',
                'id' => 'share-expired',
                'expiration' => '17.08.2026 23:59 Uhr',
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
        ->assertSee('Ablaufende Freigaben')
        ->assertSee('Bald-Ablauf')
        ->assertSee('Alt-Ordner')
        ->assertSee('Gültig bis 20.08.2026 23:59 Uhr')
        ->assertSee('seit 1 Tag abgelaufen')
        ->assertSee('Gültigkeit verlängern')
        ->assertDontSee('Spaeter-Ablauf');
});

it('verlaengert die gueltigkeit einer abgelaufenen freigabe in der liste', function (): void {
    $user = User::factory()->create([
        'username' => 'expire.extend',
        'vorname' => 'Expire',
        'nachname' => 'Extend',
        'email' => 'expire.extend@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([
            cloudshareSampleShare([
                'name' => 'Alt-Ordner',
                'id' => 'share-expired',
                'expiration' => '17.08.2026 23:59 Uhr',
            ]),
        ]);
        $mock->shouldReceive('quota')->andReturn(null);
        $mock->shouldReceive('listFiles')->andReturn([]);
        $mock->shouldReceive('extendShareExpiration')
            ->once()
            ->withArgs(function (mixed $authUser, string $shareId, string $expiresAt): bool {
                return $shareId === 'share-expired' && $expiresAt === '2026-08-25';
            })
            ->andReturn(cloudshareSampleShare([
                'name' => 'Alt-Ordner',
                'id' => 'share-expired',
                'expiration' => '25.08.2026 00:00 Uhr',
            ]));
    });

    actingAs($user);

    Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->call('openExtendModal', 'share-expired')
        ->assertSet('showExtendModal', true)
        ->assertSet('extendShareName', 'Alt-Ordner')
        ->set('extendExpiresAt', '2026-08-25')
        ->call('extendShareExpiration')
        ->assertHasNoErrors()
        ->assertSet('showExtendModal', false);
});

it('lehnt verlaengerung auf heute im formular ab', function (): void {
    $user = User::factory()->create([
        'username' => 'expire.extend.today',
        'vorname' => 'Expire',
        'nachname' => 'Today',
        'email' => 'expire.extend.today@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([
            cloudshareSampleShare([
                'name' => 'Bald-Ablauf',
                'id' => 'share-soon',
                'expiration' => '20.08.2026 23:59 Uhr',
            ]),
        ]);
        $mock->shouldReceive('quota')->andReturn(null);
        $mock->shouldReceive('listFiles')->andReturn([]);
        $mock->shouldReceive('extendShareExpiration')->never();
    });

    actingAs($user);

    Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->call('openExtendModal', 'share-soon')
        ->set('extendExpiresAt', '2026-08-18')
        ->call('extendShareExpiration')
        ->assertHasErrors(['extendExpiresAt'])
        ->assertSet('showExtendModal', true);
});

it('oeffnet das verlaengern-modal nicht fuer spaeter ablaufende freigaben', function (): void {
    $user = User::factory()->create([
        'username' => 'expire.extend.later',
        'vorname' => 'Expire',
        'nachname' => 'Later',
        'email' => 'expire.extend.later@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([
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

    Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->call('openExtendModal', 'share-later')
        ->assertSet('showExtendModal', false);
});

it('verlaengert die gueltigkeit aus dem widget', function (): void {
    $user = User::factory()->create([
        'username' => 'expire.widget.extend',
        'vorname' => 'Expire',
        'nachname' => 'WidgetExtend',
        'email' => 'expire.widget.extend@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([
            cloudshareSampleShare([
                'name' => 'Bald-Ablauf',
                'id' => 'share-soon',
                'expiration' => '20.08.2026 23:59 Uhr',
            ]),
        ]);
        $mock->shouldReceive('extendShareExpiration')
            ->once()
            ->withArgs(function (mixed $authUser, string $shareId, string $expiresAt): bool {
                return $shareId === 'share-soon' && $expiresAt === '2026-08-25';
            })
            ->andReturn(cloudshareSampleShare([
                'name' => 'Bald-Ablauf',
                'id' => 'share-soon',
                'expiration' => '25.08.2026 00:00 Uhr',
            ]));
    });

    actingAs($user);

    Livewire::test('intranet-app-cloudshare::apps.cloudshare.widgets.ablaufende-freigaben')
        ->call('openExtendModal', 'share-soon', 'Bald-Ablauf')
        ->assertSet('showExtendModal', true)
        ->set('extendExpiresAt', '2026-08-25')
        ->call('extendShareExpiration')
        ->assertHasNoErrors()
        ->assertSet('showExtendModal', false);
});
