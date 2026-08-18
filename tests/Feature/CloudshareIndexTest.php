<?php

declare(strict_types=1);

use App\Models\User;
use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Hwkdo\IntranetAppCloudshare\Data\AppSettings;
use Hwkdo\IntranetAppCloudshare\Data\UserSettings;
use Hwkdo\IntranetAppCloudshare\IntranetAppCloudshare;
use Hwkdo\IntranetAppCloudshare\Mail\CloudshareSharedMail;
use Hwkdo\IntranetAppCloudshare\Models\IntranetAppCloudshareSettings;
use Hwkdo\MsGraphLaravel\Exceptions\MicrosoftDelegatedTokenMissingException;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportLazyLoading\SupportLazyLoading;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function (): void {
    Permission::findOrCreate('see-app-cloudshare', 'web');
    Permission::findOrCreate('manage-app-cloudshare', 'web');
    Livewire::withoutLazyLoading();
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

it('verwendet cloud share als produktnamen', function (): void {
    expect(IntranetAppCloudshare::app_name())->toBe('Cloud Share');
});

it('zeigt beim ersten seitenaufruf einen hinweis auf den microsoft-abruf', function (): void {
    $user = User::factory()->create([
        'username' => 'graph.loading',
        'vorname' => 'Graph',
        'nachname' => 'Loading',
        'email' => 'graph.loading@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    actingAs($user);

    SupportLazyLoading::$disableWhileTesting = false;

    get(route('apps.cloudshare.index'))
        ->assertSuccessful()
        ->assertSee('Daten werden von Microsoft geladen')
        ->assertSee('Cloud Share ruft Ihre Freigaben')
        ->assertSee('Freigaben und Dateien aus OneDrive')
        ->assertDontSee('Sie haben noch keine Freigaben');
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
        ->assertSee('Cloud Share')
        ->assertSee('https://example.com/demo')
        ->assertSee('Link kopieren')
        ->assertSee('Link öffnen')
        ->assertSee('Freigaben')
        ->assertSeeInOrder([
            'Passwortschutz',
            'nicht aktiviert',
            'Gültig bis',
            '01.02.2026 10:00 Uhr',
            'Gast-Upload',
            'nicht aktiviert',
        ])
        ->assertDontSee('Passwortgeschützt');
});

it('stellt passwortschutz, gültigkeit und gast-upload zeilenweise dar', function (): void {
    $user = User::factory()->create([
        'username' => 'share.properties',
        'vorname' => 'Share',
        'nachname' => 'Properties',
        'email' => 'share.properties@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([
            cloudshareSampleShare([
                'name' => 'Projekt-X',
                'id' => 'share-1',
                'password' => true,
                'expiration' => '31.12.2026 23:59 Uhr',
                'writeable' => false,
            ]),
            cloudshareSampleShare([
                'name' => 'Ohne-Ablauf',
                'id' => 'share-2',
                'password' => false,
                'expiration' => null,
                'writeable' => true,
            ]),
        ]);
        $mock->shouldReceive('quota')->andReturn(null);
        $mock->shouldReceive('listFiles')->andReturn([]);
    });

    actingAs($user);

    Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->assertSuccessful()
        ->assertSeeInOrder([
            'Projekt-X',
            'Passwortschutz',
            'aktiviert',
            'Gültig bis',
            '31.12.2026 23:59 Uhr',
            'Gast-Upload',
            'nicht aktiviert',
            'Ohne-Ablauf',
            'Passwortschutz',
            'nicht aktiviert',
            'Gültig bis',
            'ohne Ablaufdatum',
            'Gast-Upload',
            'aktiviert',
        ])
        ->assertDontSee('Passwortgeschützt');
});

it('stellt freigaben als eingeklappte klappliste dar', function (): void {
    $user = User::factory()->create([
        'username' => 'share.accordion',
        'vorname' => 'Share',
        'nachname' => 'Accordion',
        'email' => 'share.accordion@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([
            cloudshareSampleShare([
                'name' => 'Projekt-X',
                'id' => 'share-1',
                'password' => true,
                'writeable' => true,
                'created_at' => '17.08.2026 10:00',
            ]),
        ]);
        $mock->shouldReceive('quota')->andReturn(null);
        $mock->shouldReceive('listFiles')->andReturn([
            [
                'file' => 'angebot.pdf',
                'href' => 'https://example.com/angebot.pdf',
                'modified' => '17.08.2026 10:00',
                'size' => 1024,
                'id' => 'file-1',
            ],
        ]);
    });

    actingAs($user);

    $component = Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->assertSuccessful()
        ->assertSee('1 Freigabe')
        ->assertSee('Projekt-X')
        ->assertSee('erstellt 17.08.2026 10:00')
        ->assertSee('Passwort')
        ->assertSee('Gast-Upload')
        ->assertSee('1 Datei')
        ->assertSeeHtml('data-flux-accordion')
        ->assertSeeHtml('glass-card')
        ->assertSeeHtml('aria-expanded="false"');

    $badges = collect($component->instance()->shareHeaderBadges([
        'id' => 'share-1',
        'password' => true,
        'writeable' => true,
        'expiration' => '31.12.2026 23:59 Uhr',
    ]))->pluck('label')->all();

    expect($badges)->toBe(['Passwort', 'Gast-Upload', '1 Datei']);
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

it('lehnt gültigkeit am heutigen tag im formular ab', function (): void {
    $user = User::factory()->create([
        'username' => 'date.user',
        'vorname' => 'Date',
        'nachname' => 'User',
        'email' => 'date.user@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([]);
        $mock->shouldReceive('quota')->andReturn(null);
        $mock->shouldReceive('createShare')->never();
    });

    actingAs($user);

    Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->set('showCreateModal', true)
        ->set('newName', 'Demo')
        ->set('newExpiresAt', now()->toDateString())
        ->call('createShare')
        ->assertHasErrors(['newExpiresAt']);
});

it('zeigt deutsche validierungsmeldung bei zu kurzem passwort', function (): void {
    $user = User::factory()->create([
        'username' => 'password.user',
        'vorname' => 'Password',
        'nachname' => 'User',
        'email' => 'password.user@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([]);
        $mock->shouldReceive('quota')->andReturn(null);
        $mock->shouldReceive('createShare')->never();
    });

    actingAs($user);

    $component = Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->set('showCreateModal', true)
        ->set('newName', 'Demo')
        ->set('newPassword', 'kurz')
        ->set('newExpiresAt', now()->addDay()->toDateString())
        ->call('createShare')
        ->assertHasErrors(['newPassword']);

    expect($component->errors()->first('newPassword'))
        ->toBe('Passwort muss mindestens 8 Zeichen lang sein.');
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

it('pollt dateien nur bei freigaben mit gast-upload', function (): void {
    $user = User::factory()->create([
        'username' => 'poll.user',
        'vorname' => 'Poll',
        'nachname' => 'User',
        'email' => 'poll.user@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([
            cloudshareSampleShare([
                'name' => 'Gastordner',
                'id' => 'share-guest',
                'writeable' => true,
            ]),
        ]);
        $mock->shouldReceive('quota')->andReturn(null);
        $mock->shouldReceive('listFiles')->andReturn([]);
    });

    actingAs($user);

    Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->assertSuccessful()
        ->assertSee('Freigaben mit Gast-Upload werden automatisch aktualisiert.')
        ->assertSeeHtml('wire:poll.30s.visible="refreshGuestUploads"');
});

it('pollt nicht wenn keine gast-upload-freigabe existiert', function (): void {
    $user = User::factory()->create([
        'username' => 'nopoll.user',
        'vorname' => 'NoPoll',
        'nachname' => 'User',
        'email' => 'nopoll.user@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([
            cloudshareSampleShare([
                'name' => 'Nur Lesen',
                'id' => 'share-readonly',
                'writeable' => false,
            ]),
        ]);
        $mock->shouldReceive('quota')->andReturn(null);
        $mock->shouldReceive('listFiles')->andReturn([]);
    });

    actingAs($user);

    Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->assertSuccessful()
        ->assertDontSee('Freigaben mit Gast-Upload werden automatisch aktualisiert.')
        ->assertDontSeeHtml('refreshGuestUploads');
});

it('zeigt neue gast-dateien nach dem polling ohne seitenneuladen', function (): void {
    $user = User::factory()->create([
        'username' => 'guestfile.user',
        'vorname' => 'Guest',
        'nachname' => 'File',
        'email' => 'guestfile.user@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->once()->andReturn([
            cloudshareSampleShare([
                'name' => 'Gastordner',
                'id' => 'share-guest',
                'writeable' => true,
            ]),
        ]);
        $mock->shouldReceive('quota')->once()->andReturn(null);
        $mock->shouldReceive('listFiles')->twice()->andReturn(
            [],
            [[
                'file' => 'vom-gast.pdf',
                'href' => 'https://example.com/vom-gast.pdf',
                'modified' => '18.08.2026 10:25',
                'size' => 2048,
                'id' => 'file-guest-1',
            ]],
        );
    });

    actingAs($user);

    $component = Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->assertDontSee('vom-gast.pdf')
        ->assertDontSee('Aktualisiert');

    expect($component->instance()->fileIsNewSinceOpen('file-guest-1'))->toBeFalse()
        ->and($component->instance()->shareHasUpdatesSinceOpen('share-guest'))->toBeFalse();

    $component->call('refreshGuestUploads')
        ->assertSee('vom-gast.pdf')
        ->assertSee('Aktualisiert');

    expect($component->instance()->fileIsNewSinceOpen('file-guest-1'))->toBeTrue()
        ->and($component->instance()->shareHasUpdatesSinceOpen('share-guest'))->toBeTrue();
});

it('hebt nur seit seitenaufruf hinzugekommene dateien hervor', function (): void {
    $user = User::factory()->create([
        'username' => 'highlight.user',
        'vorname' => 'Highlight',
        'nachname' => 'User',
        'email' => 'highlight.user@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $existingFile = [
        'file' => 'alt.pdf',
        'href' => 'https://example.com/alt.pdf',
        'modified' => '17.08.2026 09:00',
        'size' => 1024,
        'id' => 'file-old',
    ];
    $newFile = [
        'file' => 'vom-gast.pdf',
        'href' => 'https://example.com/vom-gast.pdf',
        'modified' => '18.08.2026 10:25',
        'size' => 2048,
        'id' => 'file-guest-1',
    ];

    $this->mock(CloudshareServiceInterface::class, function ($mock) use ($existingFile, $newFile): void {
        $mock->shouldReceive('listShares')->once()->andReturn([
            cloudshareSampleShare([
                'name' => 'Gastordner',
                'id' => 'share-guest',
                'writeable' => true,
            ]),
        ]);
        $mock->shouldReceive('quota')->once()->andReturn(null);
        $mock->shouldReceive('listFiles')->twice()->andReturn(
            [$existingFile],
            [$existingFile, $newFile],
        );
    });

    actingAs($user);

    $component = Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->assertSee('alt.pdf')
        ->assertDontSee('Aktualisiert');

    expect($component->instance()->fileIsNewSinceOpen('file-old'))->toBeFalse();

    $component->call('refreshGuestUploads')
        ->assertSee('vom-gast.pdf')
        ->assertSee('Aktualisiert');

    expect($component->instance()->fileIsNewSinceOpen('file-old'))->toBeFalse()
        ->and($component->instance()->fileIsNewSinceOpen('file-guest-1'))->toBeTrue()
        ->and($component->instance()->shareHasUpdatesSinceOpen('share-guest'))->toBeTrue();
});

it('nimmt das polling-intervall aus den appsettings', function (): void {
    $user = User::factory()->create([
        'username' => 'pollsettings.user',
        'vorname' => 'Poll',
        'nachname' => 'Settings',
        'email' => 'pollsettings.user@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    IntranetAppCloudshareSettings::query()->delete();
    IntranetAppCloudshareSettings::query()->create([
        'version' => 1,
        'settings' => new AppSettings(guestUploadPollSeconds: 15),
    ]);

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([
            cloudshareSampleShare([
                'name' => 'Gastordner',
                'id' => 'share-guest',
                'writeable' => true,
            ]),
        ]);
        $mock->shouldReceive('quota')->andReturn(null);
        $mock->shouldReceive('listFiles')->andReturn([]);
    });

    actingAs($user);

    Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->assertSuccessful()
        ->assertSeeHtml('wire:poll.15s.visible="refreshGuestUploads"');
});

it('deaktiviert polling wenn das intervall in den appsettings 0 ist', function (): void {
    $user = User::factory()->create([
        'username' => 'polloff.user',
        'vorname' => 'Poll',
        'nachname' => 'Off',
        'email' => 'polloff.user@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    IntranetAppCloudshareSettings::query()->delete();
    IntranetAppCloudshareSettings::query()->create([
        'version' => 1,
        'settings' => new AppSettings(guestUploadPollSeconds: 0),
    ]);

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([
            cloudshareSampleShare([
                'name' => 'Gastordner',
                'id' => 'share-guest',
                'writeable' => true,
            ]),
        ]);
        $mock->shouldReceive('quota')->andReturn(null);
        $mock->shouldReceive('listFiles')->andReturn([]);
    });

    actingAs($user);

    Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->assertSuccessful()
        ->assertDontSee('Freigaben mit Gast-Upload werden automatisch aktualisiert.')
        ->assertDontSeeHtml('refreshGuestUploads');
});

it('setzt den mail-betreff mit dem namen der freigabe', function (): void {
    $user = User::factory()->create([
        'username' => 'sharemail.subject',
        'vorname' => 'Share',
        'nachname' => 'Subject',
        'email' => 'sharemail.subject@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([
            cloudshareSampleShare([
                'name' => 'Projekt-X',
                'id' => 'share-1',
            ]),
        ]);
        $mock->shouldReceive('quota')->andReturn(null);
        $mock->shouldReceive('listFiles')->andReturn([]);
        $mock->shouldReceive('previewShareMail')->once()->andReturn('<html></html>');
    });

    actingAs($user);

    Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->call('openShareModal', 'share-1')
        ->assertSet('showShareModal', true)
        ->assertSet('shareMailSubject', 'Der Cloud-Ordner Projekt-X wurde für Sie freigegeben');
});

it('schliesst das teilen-modal nach erfolgreichem mailversand', function (): void {
    $user = User::factory()->create([
        'username' => 'sharemail.close',
        'vorname' => 'Share',
        'nachname' => 'Close',
        'email' => 'sharemail.close@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([
            cloudshareSampleShare([
                'name' => 'Projekt-X',
                'id' => 'share-1',
            ]),
        ]);
        $mock->shouldReceive('quota')->andReturn(null);
        $mock->shouldReceive('listFiles')->andReturn([]);
        $mock->shouldReceive('previewShareMail')->andReturn('<html></html>');
        $mock->shouldReceive('sendShareMail')
            ->once()
            ->andReturn([
                'bitwarden_sent' => false,
                'bitwarden_error' => null,
            ]);
    });

    actingAs($user);

    Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->call('openShareModal', 'share-1')
        ->set('shareMailEmail', 'gast@example.com')
        ->call('sendShareMail')
        ->assertHasNoErrors()
        ->assertSet('showShareModal', false);
});

it('laesst das teilen-modal bei validierungsfehlern geoeffnet', function (): void {
    $user = User::factory()->create([
        'username' => 'sharemail.invalid',
        'vorname' => 'Share',
        'nachname' => 'Invalid',
        'email' => 'sharemail.invalid@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([
            cloudshareSampleShare([
                'name' => 'Projekt-X',
                'id' => 'share-1',
            ]),
        ]);
        $mock->shouldReceive('quota')->andReturn(null);
        $mock->shouldReceive('listFiles')->andReturn([]);
        $mock->shouldReceive('previewShareMail')->andReturn('<html></html>');
        $mock->shouldReceive('sendShareMail')->never();
    });

    actingAs($user);

    Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->call('openShareModal', 'share-1')
        ->set('shareMailEmail', '')
        ->call('sendShareMail')
        ->assertHasErrors(['shareMailEmail'])
        ->assertSet('showShareModal', true);
});

it('erzeugt den mail-betreff aus dem namen der freigabe', function (): void {
    expect(CloudshareSharedMail::subjectForShare('Projekt-X'))
        ->toBe('Der Cloud-Ordner Projekt-X wurde für Sie freigegeben')
        ->and(CloudshareSharedMail::subjectForShare('   '))
        ->toBe(CloudshareSharedMail::DEFAULT_SUBJECT);

    expect(CloudshareSharedMail::DEFAULT_SUBJECT)
        ->toBe('Der Cloud-Ordner wurde für Sie freigegeben');
});

it('fuellt fehlendes polling-intervall in alten appsettings mit default', function (): void {
    $settings = AppSettings::from([
        'hinweisText' => '',
        'defaultBwSendMaxAccessCount' => 1,
        'defaultBwSendDeleteInDays' => 7,
    ]);

    expect($settings->guestUploadPollSeconds)->toBe(30);
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
        ->assertSee('läuft bald ab')
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
        ->and($settings->expiringSoonDays)->toBe(7)
        ->and($settings->toArray())->not->toHaveKey('defaultViewMode');
});

it('bietet im upload-modal eine drag-and-drop-flaeche', function (): void {
    $user = User::factory()->create([
        'username' => 'upload.drop',
        'vorname' => 'Upload',
        'nachname' => 'Drop',
        'email' => 'upload.drop@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([
            cloudshareSampleShare([
                'name' => 'Projekt-X',
                'id' => 'share-1',
            ]),
        ]);
        $mock->shouldReceive('quota')->andReturn(null);
        $mock->shouldReceive('listFiles')->andReturn([]);
    });

    actingAs($user);

    Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->call('openUploadModal', 'share-1', 'Projekt-X')
        ->assertSet('showUploadModal', true)
        ->assertSee('Datei hochladen')
        ->assertSee('Datei hierher ziehen oder klicken')
        ->assertSee('Eine Datei, maximal 250 MB');
});

it('kann die ausgewaehlte upload-datei wieder entfernen', function (): void {
    $user = User::factory()->create([
        'username' => 'upload.remove',
        'vorname' => 'Upload',
        'nachname' => 'Remove',
        'email' => 'upload.remove@example.com',
    ]);
    $user->givePermissionTo('see-app-cloudshare');

    $this->mock(CloudshareServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('listShares')->andReturn([
            cloudshareSampleShare([
                'name' => 'Projekt-X',
                'id' => 'share-1',
            ]),
        ]);
        $mock->shouldReceive('quota')->andReturn(null);
        $mock->shouldReceive('listFiles')->andReturn([]);
    });

    actingAs($user);

    $file = UploadedFile::fake()->create('angebot.pdf', 100, 'application/pdf');

    Livewire::test('intranet-app-cloudshare::apps.cloudshare.index')
        ->call('openUploadModal', 'share-1', 'Projekt-X')
        ->set('uploadFile', $file)
        ->assertSee('angebot.pdf')
        ->call('removeUploadFile')
        ->assertSet('uploadFile', null)
        ->assertDontSee('angebot.pdf');
});
