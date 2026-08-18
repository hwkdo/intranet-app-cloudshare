<?php

declare(strict_types=1);

use App\Models\User;
use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Hwkdo\IntranetAppCloudshare\Data\UserSettings;
use Hwkdo\MsGraphLaravel\Exceptions\MicrosoftDelegatedTokenMissingException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function (): void {
    Permission::findOrCreate('see-app-cloudshare', 'web');
    Permission::findOrCreate('manage-app-cloudshare', 'web');
});

it('verbietet den index ohne permission', function (): void {
    $user = User::factory()->create([
        'username' => 'no.perm',
        'vorname' => 'No',
        'nachname' => 'Perm',
    ]);

    actingAs($user);

    get(route('apps.cloudshare.index'))->assertForbidden();
});

it('zeigt den index mit permission und gemocktem service', function (): void {
    $user = User::factory()->create([
        'username' => 'with.perm',
        'vorname' => 'With',
        'nachname' => 'Perm',
        'email' => 'with.perm@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([
            [
                'name' => 'Demo',
                'id' => 'share-1',
                'url' => 'https://example.com/demo',
                'created_at' => '01.01.2026 10:00',
                'password' => false,
                'expiration' => '01.02.2026 10:00 Uhr',
                'writeable' => false,
            ],
        ]);
        $mock->shouldReceive('quota')->andReturn([
            'quota_free' => 500,
            'quota_used' => 500,
            'quota_total' => 1000,
            'quota_relative' => 50.0,
        ]);
        $mock->shouldReceive('listFiles')->andReturn([]);
    });

    actingAs($user);

    Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->assertSuccessful()
        ->assertSee('Demo')
        ->assertSee('https://example.com/demo')
        ->assertSee('Link kopieren')
        ->assertSee('Link öffnen')
        ->assertSee('Freigaben');
});

it('filtert freigaben nach namen und enthaltenen dateien', function (): void {
    $user = User::factory()->create([
        'username' => 'search.user',
        'vorname' => 'Search',
        'nachname' => 'User',
        'email' => 'search.user@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([
            cloudshareSampleShare([
                'name' => 'Projekt',
                'id' => 'share-projekt',
                'url' => 'https://example.com/projekt',
            ]),
            cloudshareSampleShare([
                'name' => 'Vertrag',
                'id' => 'share-vertrag',
                'url' => 'https://example.com/vertrag',
            ]),
        ]);
        $mock->shouldReceive('quota')->andReturn(null);
        $mock->shouldReceive('listFiles')->andReturnUsing(function (mixed $user, string $folderName): array {
            return match ($folderName) {
                'Projekt' => [[
                    'file' => 'angebot.pdf',
                    'href' => 'https://example.com/angebot.pdf',
                    'modified' => '01.01.2026 10:00',
                    'size' => 1024,
                    'id' => 'file-angebot',
                ]],
                'Vertrag' => [[
                    'file' => 'nda.docx',
                    'href' => 'https://example.com/nda.docx',
                    'modified' => '02.01.2026 10:00',
                    'size' => 2048,
                    'id' => 'file-nda',
                ]],
                default => [],
            };
        });
    });

    actingAs($user);

    $component = Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->assertSee('Projekt')
        ->assertSee('Vertrag')
        ->assertSee('angebot.pdf')
        ->assertSee('nda.docx');

    expect(collect($component->instance()->filteredShares)->pluck('name')->all())
        ->toBe(['Projekt', 'Vertrag']);

    $component->set('search', 'projekt')
        ->assertSee('1 von 2 Freigaben');

    expect(collect($component->instance()->filteredShares)->pluck('name')->all())
        ->toBe(['Projekt']);

    $component->set('search', 'nda');

    expect(collect($component->instance()->filteredShares)->pluck('name')->all())
        ->toBe(['Vertrag']);

    $component->set('search', 'gibt-es-nicht')
        ->assertSee('Keine Treffer');

    expect($component->instance()->filteredShares)->toBe([]);
});

it('validiert neue freigabe im livewire formular', function (): void {
    $user = User::factory()->create([
        'username' => 'form.user',
        'vorname' => 'Form',
        'nachname' => 'User',
        'email' => 'form.user@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([]);
        $mock->shouldReceive('quota')->andReturn(null);
    });

    actingAs($user);

    Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->set('showCreateModal', true)
        ->set('newName', '')
        ->set('newExpiresAt', '')
        ->call('createShare')
        ->assertHasErrors(['newName', 'newExpiresAt']);
});

it('verbietet admin ohne manage permission', function (): void {
    $user = User::factory()->create([
        'username' => 'user.only',
        'vorname' => 'User',
        'nachname' => 'Only',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    actingAs($user);

    get(route('apps.cloudshare.admin.index'))->assertForbidden();
});

it('zeigt den hinweis zur microsoft-anmeldung wenn der delegated token fehlt', function (): void {
    $user = User::factory()->create([
        'username' => 'no.token',
        'vorname' => 'No',
        'nachname' => 'Token',
        'email' => 'no.token@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')
            ->andThrow(MicrosoftDelegatedTokenMissingException::missingRefreshToken());
        $mock->shouldReceive('quota')->never();
        $mock->shouldReceive('listFiles')->never();
    });

    actingAs($user);

    Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->assertSuccessful()
        ->assertSee('Microsoft-Anmeldung erforderlich')
        ->assertSee('Mit Microsoft anmelden')
        ->assertSet('needsMicrosoftLogin', true);
});

it('zeigt in den benutzereinstellungen keinen anzeigemodus', function (): void {
    $user = User::factory()->create([
        'username' => 'settings.user',
        'vorname' => 'Settings',
        'nachname' => 'User',
        'email' => 'settings.user@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    actingAs($user);

    Livewire::test('intranet-app-cloudshare::apps.cloudshare.settings.user')
        ->assertSuccessful()
        ->assertDontSee('Raster-Ansicht')
        ->assertDontSee('Listen-Ansicht')
        ->assertDontSee('Tabellen-Ansicht')
        ->assertDontSee('Standard-Anzeigemodus');
});

it('lädt user settings auch wenn altes defaultViewMode in den daten steht', function (): void {
    $settings = UserSettings::from([
        'defaultViewMode' => 'grid',
        'notificationsEnabled' => false,
    ]);

    expect($settings->notificationsEnabled)->toBeFalse()
        ->and($settings->toArray())->not->toHaveKey('defaultViewMode');
});
