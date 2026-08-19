<?php

declare(strict_types=1);

use App\Models\User;
use DateTimeInterface;
use Hwkdo\HwkAdminLaravel\HwkAdminService;
use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Hwkdo\IntranetAppCloudshare\Data\AppSettings;
use Hwkdo\IntranetAppCloudshare\Mail\CloudsharePasswordSendMail;
use Hwkdo\IntranetAppCloudshare\Mail\CloudshareSharedMail;
use Hwkdo\IntranetAppCloudshare\Models\CloudshareShare;
use Hwkdo\IntranetAppCloudshare\Models\IntranetAppCloudshareSettings;
use Hwkdo\IntranetAppCloudshare\Services\CloudshareService;
use Hwkdo\MsGraphLaravel\Exceptions\MicrosoftDelegatedTokenMissingException;
use Hwkdo\MsGraphLaravel\Interfaces\MsGraphDelegatedOneDriveFactoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\mock;

beforeEach(function (): void {
    Permission::findOrCreate('see-app-cloudshare', 'web');
    Mail::fake();
    Cache::flush();
    config(['intranet-app-cloudshare.graph_cache_seconds' => 0]);

    if (! Schema::hasTable('intranet_app_cloudshare_shares')) {
        Schema::create('intranet_app_cloudshare_shares', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('onedrive_item_id');
            $table->string('folder_name');
            $table->text('password')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'onedrive_item_id']);
        });
    } else {
        CloudshareShare::query()->delete();
    }
});

function cloudshareUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'username' => 'cloudshare.tester',
        'vorname' => 'Cloud',
        'nachname' => 'Tester',
        'email' => 'cloudshare.tester@example.com',
    ], $overrides));
}

function cloudshareGraphShareFolder(string $id, string $name, ?DateTimeInterface $createdAt = null, mixed $fileSystemInfo = null): object
{
    $folderFacet = Mockery::mock();
    $folderFacet->shouldReceive('getChildCount')->andReturn(0);

    $folder = Mockery::mock();
    $folder->shouldReceive('getFolder')->andReturn($folderFacet);
    $folder->shouldReceive('getShared')->andReturn((object) []);
    $folder->shouldReceive('getId')->andReturn($id);
    $folder->shouldReceive('getName')->andReturn($name);
    $folder->shouldReceive('getCreatedDateTime')->andReturn($createdAt);
    $folder->shouldReceive('getFileSystemInfo')->andReturn($fileSystemInfo);

    return $folder;
}

if (! function_exists('cloudshareAnonymousPermission')) {
    function cloudshareAnonymousPermission(
        string $permId = 'perm-1',
        bool $writeable = false,
        mixed $expiration = null,
        string $url = 'https://example.com/share',
        bool $hasPassword = false,
    ): object {
        $link = Mockery::mock();
        $link->shouldReceive('getWebUrl')->andReturn($url);

        $perm = Mockery::mock();
        $perm->shouldReceive('getId')->andReturn($permId);
        $perm->shouldReceive('getLink')->andReturn($link);
        $perm->shouldReceive('getExpirationDateTime')->andReturn($expiration);
        $perm->shouldReceive('getHasPassword')->andReturn($hasPassword);
        $perm->shouldReceive('getRoles')->andReturn($writeable ? ['write'] : ['read']);

        return $perm;
    }
}

it('listet freigaben über den oneDrive service', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    $folderFacet = Mockery::mock();
    $folderFacet->shouldReceive('getChildCount')->andReturn(3);

    $folder = Mockery::mock();
    $folder->shouldReceive('getFolder')->andReturn($folderFacet);
    $folder->shouldReceive('getShared')->andReturn((object) []);
    $folder->shouldReceive('getId')->andReturn('folder-1');
    $folder->shouldReceive('getName')->andReturn('Projekt');
    $folder->shouldReceive('getFileSystemInfo')->andReturn(null);

    $link = Mockery::mock();
    $link->shouldReceive('getWebUrl')->andReturn('https://example.com/share');

    $perm = Mockery::mock();
    $perm->shouldReceive('getLink')->andReturn($link);
    $perm->shouldReceive('getExpirationDateTime')->andReturn(null);
    $perm->shouldReceive('getHasPassword')->andReturn(true);
    $perm->shouldReceive('getRoles')->andReturn(['write']);

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('makeFolder')->once()->with(Mockery::type('string'), 'Cloudshare')->andReturn($folder);
    $oneDrive->shouldReceive('getUserDriveContent')
        ->once()
        ->with(Mockery::type('string'), 'Cloudshare', ['expand' => ['permissions']])
        ->andReturn([$folder]);
    $oneDrive->shouldReceive('getDriveItemPermissions')->once()->with(Mockery::type('string'), 'folder-1', 'anonymous')->andReturn(collect([$perm]));

    $shares = app(CloudshareService::class)->listShares($user);

    expect($shares)->toHaveCount(1)
        ->and($shares[0]['name'])->toBe('Projekt')
        ->and($shares[0]['url'])->toBe('https://example.com/share')
        ->and($shares[0]['password'])->toBeTrue()
        ->and($shares[0]['has_stored_password'])->toBeFalse()
        ->and($shares[0]['writeable'])->toBeTrue()
        ->and($shares[0]['file_count'])->toBe(3);
});

it('sortiert freigaben nach erstellungsdatum absteigend', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    $older = cloudshareGraphShareFolder('folder-old', 'Alt', new DateTimeImmutable('2026-01-01 08:00:00'));
    $newer = cloudshareGraphShareFolder('folder-new', 'Neu', new DateTimeImmutable('2026-08-18 12:00:00'));

    $fileSystemInfo = Mockery::mock();
    $fileSystemInfo->shouldReceive('getCreatedDateTime')->andReturn(new DateTimeImmutable('2026-08-17 09:00:00'));

    $fromFileSystemInfo = cloudshareGraphShareFolder('folder-fs', 'Mitte', null, $fileSystemInfo);

    $link = Mockery::mock();
    $link->shouldReceive('getWebUrl')->andReturn('https://example.com/share');

    $perm = Mockery::mock();
    $perm->shouldReceive('getLink')->andReturn($link);
    $perm->shouldReceive('getExpirationDateTime')->andReturn(null);
    $perm->shouldReceive('getHasPassword')->andReturn(false);
    $perm->shouldReceive('getRoles')->andReturn(['read']);

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('makeFolder')->once()->andReturn($older);
    $oneDrive->shouldReceive('getUserDriveContent')->once()->andReturn([$older, $fromFileSystemInfo, $newer]);
    $oneDrive->shouldReceive('getDriveItemPermissions')->andReturn(collect([$perm]));

    $shares = app(CloudshareService::class)->listShares($user);

    expect($shares)->toHaveCount(3)
        ->and(array_column($shares, 'id'))->toBe(['folder-new', 'folder-fs', 'folder-old'])
        ->and($shares[0]['created_at'])->toBe('18.08.2026 12:00')
        ->and($shares[1]['created_at'])->toBe('17.08.2026 09:00')
        ->and($shares[2]['created_at'])->toBe('01.01.2026 08:00');
});

it('erzeugt beim listen keinen neuen graph-link wenn keine url bekannt ist', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    $folder = Mockery::mock();
    $folder->shouldReceive('getFolder')->andReturn((object) []);
    $folder->shouldReceive('getShared')->andReturn((object) []);
    $folder->shouldReceive('getId')->andReturn('folder-2');
    $folder->shouldReceive('getName')->andReturn('Upload');
    $folder->shouldReceive('getFileSystemInfo')->andReturn(null);

    $link = Mockery::mock();
    $link->shouldReceive('getWebUrl')->andReturn('');

    $perm = Mockery::mock();
    $perm->shouldReceive('getLink')->andReturn($link);
    $perm->shouldReceive('getExpirationDateTime')->andReturn(null);
    $perm->shouldReceive('getHasPassword')->andReturn(true);
    $perm->shouldReceive('getRoles')->andReturn(['write']);

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('makeFolder')->once()->andReturn($folder);
    $oneDrive->shouldReceive('getUserDriveContent')->once()->andReturn([$folder]);
    $oneDrive->shouldReceive('getDriveItemPermissions')->once()->andReturn(collect([$perm]));
    $oneDrive->shouldNotReceive('shareReadWrite');
    $oneDrive->shouldNotReceive('shareReadOnly');

    $shares = app(CloudshareService::class)->listShares($user);

    expect($shares)->toHaveCount(1)
        ->and($shares[0]['url'])->toBe('')
        ->and($shares[0]['writeable'])->toBeTrue();
});

it('erstellt eine freigabe und speichert passwort verschluesselt', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    $createdFolder = Mockery::mock();
    $createdFolder->shouldReceive('getId')->andReturn('new-folder-id');

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('makeFolder')
        ->once()
        ->with(Mockery::type('string'), 'Cloudshare/Demo')
        ->andReturn($createdFolder);
    $oneDrive->shouldReceive('shareReadWrite')
        ->once()
        ->with(Mockery::type('string'), 'new-folder-id', 'secret123', Mockery::type('string'))
        ->andReturn('https://example.com/rw');
    $oneDrive->shouldReceive('getDriveItemPermissions')->andReturn([]);

    $share = app(CloudshareService::class)->createShare($user, [
        'name' => 'Demo',
        'password' => 'secret123',
        'expires_at' => now()->addDay()->toDateTimeString(),
        'guest_upload' => true,
    ]);

    expect($share['id'])->toBe('new-folder-id')
        ->and($share['name'])->toBe('Demo')
        ->and($share['url'])->toBe('https://example.com/rw')
        ->and($share['has_stored_password'])->toBeTrue()
        ->and($share['writeable'])->toBeTrue();

    $row = DB::table('intranet_app_cloudshare_shares')->where('onedrive_item_id', 'new-folder-id')->first();
    expect($row)->not->toBeNull()
        ->and($row->password)->not->toBe('secret123');

    if (property_exists($row, 'share_url')) {
        expect($row->share_url)->toBeNull();
    }

    $model = CloudshareShare::query()->where('onedrive_item_id', 'new-folder-id')->first();
    expect($model)->not->toBeNull()
        ->and($model->password)->toBe('secret123');
});

it('erstellt eine freigabe ohne passwort', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    $createdFolder = Mockery::mock();
    $createdFolder->shouldReceive('getId')->andReturn('no-password-folder');

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('makeFolder')
        ->once()
        ->with(Mockery::type('string'), 'Cloudshare/OhnePasswort')
        ->andReturn($createdFolder);
    $oneDrive->shouldReceive('shareReadOnly')
        ->once()
        ->with(Mockery::type('string'), 'no-password-folder', null, Mockery::type('string'))
        ->andReturn('https://example.com/ro');
    $oneDrive->shouldReceive('getDriveItemPermissions')->andReturn([]);

    $share = app(CloudshareService::class)->createShare($user, [
        'name' => 'OhnePasswort',
        'password' => null,
        'expires_at' => now()->addDay()->toDateTimeString(),
        'guest_upload' => false,
    ]);

    expect($share['id'])->toBe('no-password-folder')
        ->and($share['url'])->toBe('https://example.com/ro')
        ->and($share['password'])->toBeFalse()
        ->and($share['has_stored_password'])->toBeFalse();

    $model = CloudshareShare::query()->where('onedrive_item_id', 'no-password-folder')->first();
    expect($model)->not->toBeNull()
        ->and($model->password)->toBeNull();
});

it('gibt beim anlegen die graph-url zurück ohne sie zu speichern', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    $createdFolder = Mockery::mock();
    $createdFolder->shouldReceive('getId')->andReturn('new-folder-id');
    $createdFolder->shouldReceive('getName')->andReturn('Demo');
    $createdFolder->shouldReceive('getFileSystemInfo')->andReturn(null);

    $link = Mockery::mock();
    $link->shouldReceive('getWebUrl')->andReturn(null);

    $perm = Mockery::mock();
    $perm->shouldReceive('getLink')->andReturn($link);
    $perm->shouldReceive('getExpirationDateTime')->andReturn(now()->addDay());
    $perm->shouldReceive('getHasPassword')->andReturn(true);
    $perm->shouldReceive('getRoles')->andReturn(['write']);

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('makeFolder')
        ->once()
        ->andReturn($createdFolder);
    $oneDrive->shouldReceive('shareReadWrite')
        ->once()
        ->andReturn('https://example.com/rw');
    $oneDrive->shouldReceive('getDriveItemPermissions')->andReturn(collect([$perm]));
    $oneDrive->shouldNotReceive('shareReadOnly');

    $share = app(CloudshareService::class)->createShare($user, [
        'name' => 'Demo',
        'password' => 'secret123',
        'expires_at' => now()->addDay()->toDateTimeString(),
        'guest_upload' => true,
    ]);

    expect($share['url'])->toBe('https://example.com/rw');
});

it('validiert passwortlänge bei createShare', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    mockCloudshareOneDrive()->shouldNotReceive('makeFolder');

    app(CloudshareService::class)->createShare($user, [
        'name' => 'Demo',
        'password' => 'short',
        'expires_at' => now()->addDay()->toDateTimeString(),
        'guest_upload' => false,
    ]);
})->throws(InvalidArgumentException::class);

it('setzt die gültigkeit auf 00:00 uhr der app-zeitzone', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    $expectedExpiration = Carbon::createFromFormat('Y-m-d', '2030-01-15', config('app.timezone'))
        ->startOfDay()
        ->toIso8601String();

    $createdFolder = Mockery::mock();
    $createdFolder->shouldReceive('getId')->andReturn('expires-folder');

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('makeFolder')->once()->andReturn($createdFolder);
    $oneDrive->shouldReceive('shareReadOnly')
        ->once()
        ->with(Mockery::type('string'), 'expires-folder', null, $expectedExpiration)
        ->andReturn('https://example.com/ro');
    $oneDrive->shouldReceive('getDriveItemPermissions')->andReturn([]);

    app(CloudshareService::class)->createShare($user, [
        'name' => 'Demo',
        'password' => null,
        'expires_at' => '2030-01-15 14:30:00',
        'guest_upload' => false,
    ]);
});

it('zeigt graph-ablaufdatum in der app-zeitzone', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    $folder = cloudshareGraphShareFolder(
        'folder-tz',
        'Zeitzone',
        new DateTimeImmutable('2026-08-21 10:00:00'),
    );

    $link = Mockery::mock();
    $link->shouldReceive('getWebUrl')->andReturn('https://example.com/share');

    $perm = Mockery::mock();
    $perm->shouldReceive('getLink')->andReturn($link);
    $perm->shouldReceive('getExpirationDateTime')
        ->andReturn(new DateTime('2026-08-20 22:00:00', new DateTimeZone('UTC')));
    $perm->shouldReceive('getHasPassword')->andReturn(false);
    $perm->shouldReceive('getRoles')->andReturn(['read']);

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('makeFolder')->once()->andReturn($folder);
    $oneDrive->shouldReceive('getUserDriveContent')->once()->andReturn([$folder]);
    $oneDrive->shouldReceive('getDriveItemPermissions')->andReturn(collect([$perm]));

    $shares = app(CloudshareService::class)->listShares($user);

    expect($shares)->toHaveCount(1)
        ->and($shares[0]['expiration'])->toBe('21.08.2026 00:00 Uhr');
});

it('lehnt gültigkeit ab wenn 00:00 des tages nicht in der zukunft liegt', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    mockCloudshareOneDrive()->shouldNotReceive('makeFolder');

    app(CloudshareService::class)->createShare($user, [
        'name' => 'Demo',
        'expires_at' => now()->toDateString(),
        'guest_upload' => false,
    ]);
})->throws(InvalidArgumentException::class);

it('löscht ein item und den gespeicherten share-eintrag', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    CloudshareShare::query()->create([
        'user_id' => $user->id,
        'onedrive_item_id' => 'item-9',
        'folder_name' => 'Demo',
        'password' => 'secret123',
    ]);

    $drive = Mockery::mock();
    $drive->shouldReceive('getId')->andReturn('drive-1');

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('getUserDrive')->once()->andReturn($drive);
    $oneDrive->shouldReceive('deleteItemById')->once()->with('drive-1', 'item-9')->andReturn(true);

    expect(app(CloudshareService::class)->deleteItem($user, 'item-9'))->toBeTrue();
    expect(CloudshareShare::query()->where('onedrive_item_id', 'item-9')->exists())->toBeFalse();
});

it('liefert quota relativ', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    $quota = Mockery::mock();
    $quota->shouldReceive('getTotal')->andReturn(1000);
    $quota->shouldReceive('getUsed')->andReturn(250);
    $quota->shouldReceive('getRemaining')->andReturn(750);

    $drive = Mockery::mock();
    $drive->shouldReceive('getQuota')->andReturn($quota);

    mockCloudshareOneDrive()
        ->shouldReceive('getUserDrive')
        ->once()
        ->andReturn($drive);

    $result = app(CloudshareService::class)->quota($user);

    expect($result)->not->toBeNull()
        ->and($result['quota_relative'])->toBe(25.0)
        ->and($result['quota_used'])->toBe(250);
});

it('sendet share-mail an empfänger mit cc an absender', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    mockCloudshareOneDrive();

    $result = app(CloudshareServiceInterface::class)->sendShareMail(
        $user,
        [
            'name' => 'Demo',
            'id' => 'share-1',
            'url' => 'https://example.com/share',
            'password' => true,
            'has_stored_password' => false,
            'expiration' => '01.01.2030 12:00 Uhr',
            'writeable' => false,
        ],
        'gast@example.com',
        'Ein Cloud Ordner wurde für Sie freigegeben',
    );

    expect($result['bitwarden_sent'])->toBeFalse()
        ->and($result['bitwarden_error'])->toBeNull();

    Mail::assertSent(CloudshareSharedMail::class, function (CloudshareSharedMail $mail) use ($user): bool {
        return $mail->hasTo('gast@example.com')
            && $mail->hasCc($user->email)
            && $mail->share['url'] === 'https://example.com/share';
    });
    Mail::assertNotSent(CloudsharePasswordSendMail::class);
});

it('sendet bitwarden-send-mail wenn option aktiv und passwort gespeichert', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    CloudshareShare::query()->create([
        'user_id' => $user->id,
        'onedrive_item_id' => 'share-1',
        'folder_name' => 'Demo',
        'password' => 'secret123',
    ]);

    mockCloudshareOneDrive();
    mock(HwkAdminService::class)
        ->shouldReceive('createBitwardenSend')
        ->once()
        ->with('Cloud Share: Demo', 'secret123', 1, 7)
        ->andReturn('https://vault.example.com/send/abc');

    $result = app(CloudshareServiceInterface::class)->sendShareMail(
        $user,
        [
            'name' => 'Demo',
            'id' => 'share-1',
            'url' => 'https://example.com/share',
            'password' => true,
            'has_stored_password' => true,
            'expiration' => null,
            'writeable' => false,
        ],
        'gast@example.com',
        'Ein Cloud Ordner wurde für Sie freigegeben',
        true,
    );

    expect($result['bitwarden_sent'])->toBeTrue()
        ->and($result['bitwarden_error'])->toBeNull();

    Mail::assertSent(CloudshareSharedMail::class, 1);
    Mail::assertSent(CloudsharePasswordSendMail::class, function (CloudsharePasswordSendMail $mail) use ($user): bool {
        return $mail->hasTo('gast@example.com')
            && $mail->hasCc($user->email)
            && $mail->accessUrl === 'https://vault.example.com/send/abc'
            && $mail->shareName === 'Demo';
    });
});

it('uebergibt bitwarden-send defaults aus app settings', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    if (! Schema::hasTable('intranet_app_cloudshare_settings')) {
        Schema::create('intranet_app_cloudshare_settings', function ($table): void {
            $table->id();
            $table->integer('version')->default(1);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    IntranetAppCloudshareSettings::query()->delete();
    IntranetAppCloudshareSettings::query()->create([
        'version' => 1,
        'settings' => new AppSettings(
            hinweisText: '',
            defaultBwSendMaxAccessCount: 3,
            defaultBwSendDeleteInDays: 14,
        ),
    ]);

    CloudshareShare::query()->create([
        'user_id' => $user->id,
        'onedrive_item_id' => 'share-2',
        'folder_name' => 'Demo',
        'password' => 'secret123',
    ]);

    mockCloudshareOneDrive();
    mock(HwkAdminService::class)
        ->shouldReceive('createBitwardenSend')
        ->once()
        ->with('Cloud Share: Demo', 'secret123', 3, 14)
        ->andReturn('https://vault.example.com/send/xyz');

    $result = app(CloudshareServiceInterface::class)->sendShareMail(
        $user,
        [
            'name' => 'Demo',
            'id' => 'share-2',
            'url' => 'https://example.com/share',
            'password' => true,
            'has_stored_password' => true,
        ],
        'gast@example.com',
        'Betreff',
        true,
    );

    expect($result['bitwarden_sent'])->toBeTrue();
});

it('meldet fehler wenn bitwarden send ohne gespeichertes passwort', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    mockCloudshareOneDrive();

    $result = app(CloudshareServiceInterface::class)->sendShareMail(
        $user,
        [
            'name' => 'Demo',
            'id' => 'missing-share',
            'url' => 'https://example.com/share',
            'password' => true,
            'has_stored_password' => false,
        ],
        'gast@example.com',
        'Betreff',
        true,
    );

    expect($result['bitwarden_sent'])->toBeFalse()
        ->and($result['bitwarden_error'])->toContain('Kein hinterlegtes Passwort');

    Mail::assertSent(CloudshareSharedMail::class, 1);
    Mail::assertNotSent(CloudsharePasswordSendMail::class);
});

it('sendet nur die bitwarden-mail ohne freigabe-mail', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    CloudshareShare::query()->create([
        'user_id' => $user->id,
        'onedrive_item_id' => 'share-outlook',
        'folder_name' => 'Demo',
        'password' => 'secret123',
    ]);

    mockCloudshareOneDrive();
    mock(HwkAdminService::class)
        ->shouldReceive('createBitwardenSend')
        ->once()
        ->andReturn('https://vault.example.com/send/outlook');

    $result = app(CloudshareServiceInterface::class)->sendPasswordViaBitwarden(
        $user,
        [
            'name' => 'Demo',
            'id' => 'share-outlook',
        ],
        'gast@example.com',
    );

    expect($result['bitwarden_sent'])->toBeTrue()
        ->and($result['bitwarden_error'])->toBeNull();

    Mail::assertNotSent(CloudshareSharedMail::class);
    Mail::assertSent(CloudsharePasswordSendMail::class, 1);
});

it('sendet bitwarden-mail an mehrere empfaenger mit angepasstem zugriffslimit', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    CloudshareShare::query()->create([
        'user_id' => $user->id,
        'onedrive_item_id' => 'share-multi',
        'folder_name' => 'Demo',
        'password' => 'secret123',
    ]);

    mockCloudshareOneDrive();
    mock(HwkAdminService::class)
        ->shouldReceive('createBitwardenSend')
        ->once()
        ->with('Cloud Share: Demo', 'secret123', 2, 7)
        ->andReturn('https://vault.example.com/send/multi');

    $result = app(CloudshareServiceInterface::class)->sendPasswordViaBitwarden(
        $user,
        [
            'name' => 'Demo',
            'id' => 'share-multi',
        ],
        ['eins@example.com', 'zwei@example.com', 'eins@example.com'],
    );

    expect($result['bitwarden_sent'])->toBeTrue();

    Mail::assertSent(CloudsharePasswordSendMail::class, function (CloudsharePasswordSendMail $mail) use ($user): bool {
        return $mail->hasTo('eins@example.com')
            && $mail->hasTo('zwei@example.com')
            && $mail->hasCc($user->email);
    });
});

it('reicht fehlende microsoft tokens als exception durch', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    mock(MsGraphDelegatedOneDriveFactoryInterface::class)
        ->shouldReceive('forUser')
        ->andThrow(MicrosoftDelegatedTokenMissingException::missingRefreshToken());

    app(CloudshareService::class)->listShares($user);
})->throws(MicrosoftDelegatedTokenMissingException::class);

it('uebergibt den originalen dateinamen an onedrive', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('uploadItemToUserDrive')
        ->once()
        ->withArgs(function (string $upn, string $filename, string $path, string $subdir): bool {
            return $filename === 'test.pdf'
                && $path === '/tmp/fake'
                && str_ends_with($subdir, '/Projekt-X');
        })
        ->andReturn(true);

    app(CloudshareService::class)->uploadFile($user, 'Projekt-X', '/tmp/fake', 'test.pdf');
});

it('verlaengert die gueltigkeit einer freigabe per updateLink', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    $expectedExpiration = Carbon::createFromFormat('Y-m-d', '2030-01-15', config('app.timezone'))
        ->startOfDay()
        ->toIso8601String();

    $folder = cloudshareGraphShareFolder('folder-extend', 'Verlaengern');
    $perm = cloudshareAnonymousPermission(
        permId: 'perm-extend',
        expiration: Carbon::parse('2030-01-15 00:00:00', config('app.timezone')),
    );

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('makeFolder')->once()->andReturn($folder);
    $oneDrive->shouldReceive('getUserDriveContent')->once()->andReturn([$folder]);
    $oneDrive->shouldReceive('getDriveItemPermissions')->twice()->andReturn(collect([$perm]));
    $oneDrive->shouldReceive('updateLink')
        ->once()
        ->with(Mockery::type('string'), 'folder-extend', 'perm-extend', ['expirationDateTime' => $expectedExpiration])
        ->andReturn((object) []);
    $oneDrive->shouldNotReceive('shareReadOnly');
    $oneDrive->shouldNotReceive('shareReadWrite');

    $share = app(CloudshareService::class)->extendShareExpiration($user, 'folder-extend', '2030-01-15');

    expect($share['id'])->toBe('folder-extend')
        ->and($share['expiration'])->toBe('15.01.2030 00:00 Uhr');
});

it('erzeugt den link neu wenn updateLink nicht moeglich ist', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    CloudshareShare::query()->create([
        'user_id' => $user->id,
        'onedrive_item_id' => 'folder-recreate',
        'folder_name' => 'Neu erzeugen',
        'password' => 'secret123',
    ]);

    $expectedExpiration = Carbon::createFromFormat('Y-m-d', '2030-01-15', config('app.timezone'))
        ->startOfDay()
        ->toIso8601String();

    $folder = cloudshareGraphShareFolder('folder-recreate', 'Neu erzeugen');
    $perm = cloudshareAnonymousPermission(
        permId: '',
        writeable: true,
        expiration: Carbon::parse('2030-01-15 00:00:00', config('app.timezone')),
        hasPassword: true,
    );

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('makeFolder')->once()->andReturn($folder);
    $oneDrive->shouldReceive('getUserDriveContent')->once()->andReturn([$folder]);
    $oneDrive->shouldReceive('getDriveItemPermissions')->twice()->andReturn(collect([$perm]));
    $oneDrive->shouldNotReceive('updateLink');
    $oneDrive->shouldReceive('shareReadWrite')
        ->once()
        ->with(Mockery::type('string'), 'folder-recreate', 'secret123', $expectedExpiration)
        ->andReturn('https://example.com/rw');

    $share = app(CloudshareService::class)->extendShareExpiration($user, 'folder-recreate', '2030-01-15');

    expect($share['id'])->toBe('folder-recreate')
        ->and($share['writeable'])->toBeTrue();
});

it('lehnt verlaengerung auf ein datum in der vergangenheit ab', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    mockCloudshareOneDrive()->shouldNotReceive('makeFolder');

    app(CloudshareService::class)->extendShareExpiration($user, 'folder-extend', now()->toDateString());
})->throws(InvalidArgumentException::class);

it('wirft wenn die freigabe zum verlaengern nicht gefunden wird', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('makeFolder')->once()->andReturn(cloudshareGraphShareFolder('other', 'Andere'));
    $oneDrive->shouldReceive('getUserDriveContent')->once()->andReturn([]);

    app(CloudshareService::class)->extendShareExpiration($user, 'folder-missing', now()->addDay()->toDateString());
})->throws(InvalidArgumentException::class);

it('loescht nur freigaben die laenger als die karenzzeit abgelaufen sind', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-18 12:00:00', config('app.timezone')));

    $user = cloudshareUser();
    $user->givePermissionTo('see-app-cloudshare');

    $due = cloudshareGraphShareFolder('folder-old', 'Alt', new DateTimeImmutable('2026-08-01 08:00:00'));
    $recentlyExpired = cloudshareGraphShareFolder('folder-recent', 'Gestern', new DateTimeImmutable('2026-08-17 08:00:00'));
    $active = cloudshareGraphShareFolder('folder-active', 'Aktiv', new DateTimeImmutable('2026-08-10 08:00:00'));

    $drive = Mockery::mock();
    $drive->shouldReceive('getId')->andReturn('drive-1');

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('makeFolder')->once()->andReturn($due);
    $oneDrive->shouldReceive('getUserDriveContent')->once()->andReturn([$due, $recentlyExpired, $active]);
    $oneDrive->shouldReceive('getDriveItemPermissions')
        ->times(3)
        ->andReturnUsing(function (string $upn, string $itemId) {
            $expiration = match ($itemId) {
                'folder-old' => new DateTimeImmutable('2026-08-01 00:00:00'),
                'folder-recent' => new DateTimeImmutable('2026-08-17 23:59:00'),
                default => new DateTimeImmutable('2026-12-31 23:59:00'),
            };

            return collect([cloudshareAnonymousPermission('perm-'.$itemId, expiration: $expiration)]);
        });
    $oneDrive->shouldReceive('getUserDrive')->once()->andReturn($drive);
    $oneDrive->shouldReceive('deleteItemById')->once()->with('drive-1', 'folder-old')->andReturn(true);

    try {
        $result = app(CloudshareService::class)->purgeExpiredShares(7);

        expect($result)->toBe([
            'deleted' => 1,
            'skipped_users' => 0,
            'failed' => 0,
        ]);
    } finally {
        Carbon::setTestNow();
    }
});

it('ueberspringt benutzer ohne microsoft token beim automatischen loeschen', function (): void {
    $user = cloudshareUser();
    $user->givePermissionTo('see-app-cloudshare');

    mock(MsGraphDelegatedOneDriveFactoryInterface::class)
        ->shouldReceive('forUser')
        ->andThrow(MicrosoftDelegatedTokenMissingException::missingRefreshToken());

    $result = app(CloudshareService::class)->purgeExpiredShares(7);

    expect($result)->toBe([
        'deleted' => 0,
        'skipped_users' => 1,
        'failed' => 0,
    ]);
});

it('findet freigaben ueber den db-eintrag ohne listShares', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    CloudshareShare::query()->create([
        'user_id' => $user->id,
        'onedrive_item_id' => 'folder-db',
        'folder_name' => 'Aus-DB',
        'password' => 'secret123',
    ]);

    $perm = cloudshareAnonymousPermission(
        permId: 'perm-db',
        hasPassword: true,
        url: 'https://example.com/db-share',
    );

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('getDriveItemPermissions')
        ->once()
        ->with(Mockery::type('string'), 'folder-db', 'anonymous')
        ->andReturn(collect([$perm]));
    $oneDrive->shouldNotReceive('makeFolder');
    $oneDrive->shouldNotReceive('getUserDriveContent');

    $share = app(CloudshareService::class)->findShare($user, 'folder-db');

    expect($share)->not->toBeNull()
        ->and($share['id'])->toBe('folder-db')
        ->and($share['name'])->toBe('Aus-DB')
        ->and($share['url'])->toBe('https://example.com/db-share')
        ->and($share['has_stored_password'])->toBeTrue();
});

it('faellt bei findShare ohne db-eintrag auf graph zurueck', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    $folder = cloudshareGraphShareFolder('folder-graph', 'NurGraph');
    $perm = cloudshareAnonymousPermission(url: 'https://example.com/graph-share');

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('makeFolder')->once()->andReturn($folder);
    $oneDrive->shouldReceive('getUserDriveContent')
        ->once()
        ->with(Mockery::type('string'), 'Cloudshare', ['expand' => ['permissions']])
        ->andReturn([$folder]);
    $oneDrive->shouldReceive('getDriveItemPermissions')->once()->andReturn(collect([$perm]));

    $share = app(CloudshareService::class)->findShare($user, 'folder-graph');

    expect($share)->not->toBeNull()
        ->and($share['id'])->toBe('folder-graph')
        ->and($share['name'])->toBe('NurGraph');
});

it('faellt bei nicht unterstuetztem permissions-expand auf einzelabruf zurueck', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    $perm = cloudshareAnonymousPermission(url: 'https://example.com/fallback');
    $folder = cloudshareGraphShareFolder('folder-fallback', 'Fallback');

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('makeFolder')->once()->andReturn($folder);
    $oneDrive->shouldReceive('getUserDriveContent')
        ->once()
        ->with(Mockery::type('string'), 'Cloudshare', ['expand' => ['permissions']])
        ->andThrow(new RuntimeException('Operation not supported'));
    $oneDrive->shouldReceive('getUserDriveContent')
        ->once()
        ->with(Mockery::type('string'), 'Cloudshare')
        ->andReturn([$folder]);
    $oneDrive->shouldReceive('getDriveItemPermissions')
        ->once()
        ->with(Mockery::type('string'), 'folder-fallback', 'anonymous')
        ->andReturn(collect([$perm]));

    $shares = app(CloudshareService::class)->listShares($user);

    expect($shares)->toHaveCount(1)
        ->and($shares[0]['url'])->toBe('https://example.com/fallback');
});

it('nutzt expandierte permissions ohne getDriveItemPermissions', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    $perm = cloudshareAnonymousPermission(url: 'https://example.com/expanded');
    $folderFacet = Mockery::mock();
    $folderFacet->shouldReceive('getChildCount')->andReturn(1);

    $folder = Mockery::mock();
    $folder->shouldReceive('getFolder')->andReturn($folderFacet);
    $folder->shouldReceive('getShared')->andReturn((object) []);
    $folder->shouldReceive('getId')->andReturn('folder-expand');
    $folder->shouldReceive('getName')->andReturn('Expanded');
    $folder->shouldReceive('getCreatedDateTime')->andReturn(new DateTimeImmutable('2026-08-18 12:00:00'));
    $folder->shouldReceive('getFileSystemInfo')->andReturn(null);
    $folder->shouldReceive('getPermissions')->andReturn([$perm]);

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('makeFolder')->once()->andReturn($folder);
    $oneDrive->shouldReceive('getUserDriveContent')
        ->once()
        ->with(Mockery::type('string'), 'Cloudshare', ['expand' => ['permissions']])
        ->andReturn([$folder]);
    $oneDrive->shouldNotReceive('getDriveItemPermissions');

    $shares = app(CloudshareService::class)->listShares($user);

    expect($shares)->toHaveCount(1)
        ->and($shares[0]['url'])->toBe('https://example.com/expanded')
        ->and($shares[0]['file_count'])->toBe(1);
});

it('laedt dateien fuer mehrere freigaben per batch', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    $fileA = Mockery::mock();
    $fileA->shouldReceive('getName')->andReturn('a.pdf');
    $fileA->shouldReceive('getWebUrl')->andReturn('https://example.com/a.pdf');
    $fileA->shouldReceive('getLastModifiedDateTime')->andReturn(null);
    $fileA->shouldReceive('getSize')->andReturn(10);
    $fileA->shouldReceive('getId')->andReturn('file-a');

    $fileB = Mockery::mock();
    $fileB->shouldReceive('getName')->andReturn('b.pdf');
    $fileB->shouldReceive('getWebUrl')->andReturn('https://example.com/b.pdf');
    $fileB->shouldReceive('getLastModifiedDateTime')->andReturn(null);
    $fileB->shouldReceive('getSize')->andReturn(20);
    $fileB->shouldReceive('getId')->andReturn('file-b');

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('batchGetUserDriveContents')
        ->once()
        ->with(Mockery::type('string'), ['Cloudshare/A', 'Cloudshare/B'])
        ->andReturn([
            'Cloudshare/A' => [$fileA],
            'Cloudshare/B' => [$fileB],
        ]);
    $oneDrive->shouldNotReceive('getUserDriveContent');

    $result = app(CloudshareService::class)->listFilesForShares($user, [
        ['id' => 'share-a', 'name' => 'A'],
        ['id' => 'share-b', 'name' => 'B'],
    ]);

    expect($result)->toHaveKeys(['share-a', 'share-b'])
        ->and($result['share-a'][0]['file'])->toBe('a.pdf')
        ->and($result['share-b'][0]['file'])->toBe('b.pdf');
});

it('zeigt neue freigaben sofort trotz cache', function (): void {
    config(['intranet-app-cloudshare.graph_cache_seconds' => 60]);
    $user = cloudshareUser();
    actingAs($user);

    $existing = cloudshareGraphShareFolder('folder-old', 'Alt');
    $folderFacet = Mockery::mock();
    $folderFacet->shouldReceive('getChildCount')->andReturn(0);

    $createdFolder = Mockery::mock();
    $createdFolder->shouldReceive('getId')->andReturn('folder-new');
    $createdFolder->shouldReceive('getName')->andReturn('Neu');
    $createdFolder->shouldReceive('getFileSystemInfo')->andReturn(null);
    $createdFolder->shouldReceive('getFolder')->andReturn($folderFacet);
    $createdFolder->shouldReceive('getShared')->andReturn((object) []);
    $createdFolder->shouldReceive('getCreatedDateTime')->andReturn(now());
    $createdFolder->shouldReceive('getPermissions')->andReturn(null);

    $perm = cloudshareAnonymousPermission(url: 'https://example.com/old');
    $permNew = cloudshareAnonymousPermission(url: 'https://example.com/new', hasPassword: true);

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('makeFolder')->andReturnUsing(
        fn (string $upn, string $path): mixed => str_contains($path, 'Neu') ? $createdFolder : $existing,
    );
    $oneDrive->shouldReceive('getUserDriveContent')
        ->twice()
        ->with(Mockery::type('string'), 'Cloudshare', ['expand' => ['permissions']])
        ->andReturn([$existing], [$existing, $createdFolder]);
    $oneDrive->shouldReceive('getDriveItemPermissions')->andReturn(
        collect([$perm]),
        collect([$permNew]),
        collect([$perm]),
        collect([$permNew]),
    );
    $oneDrive->shouldReceive('shareReadOnly')
        ->once()
        ->andReturn('https://example.com/new');

    $service = app(CloudshareService::class);
    $before = $service->listShares($user);
    expect($before)->toHaveCount(1);

    $created = $service->createShare($user, [
        'name' => 'Neu',
        'password' => 'secret123',
        'expires_at' => now()->addDay()->toDateString(),
        'guest_upload' => false,
    ]);

    expect($created['id'])->toBe('folder-new');

    $after = $service->listShares($user);
    expect(array_column($after, 'id'))->toContain('folder-new');
});

it('umgeht den datei-cache bei forceRefresh fuer polling', function (): void {
    config(['intranet-app-cloudshare.graph_cache_seconds' => 60]);
    $user = cloudshareUser();
    actingAs($user);

    CloudshareShare::query()->create([
        'user_id' => $user->id,
        'onedrive_item_id' => 'share-poll',
        'folder_name' => 'Poll',
        'password' => null,
    ]);

    $empty = [];
    $withFile = Mockery::mock();
    $withFile->shouldReceive('getName')->andReturn('gast.pdf');
    $withFile->shouldReceive('getWebUrl')->andReturn('https://example.com/gast.pdf');
    $withFile->shouldReceive('getLastModifiedDateTime')->andReturn(null);
    $withFile->shouldReceive('getSize')->andReturn(1);
    $withFile->shouldReceive('getId')->andReturn('file-gast');

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('getUserDriveContent')
        ->twice()
        ->with(Mockery::type('string'), 'Cloudshare/Poll')
        ->andReturn($empty, [$withFile]);

    $service = app(CloudshareService::class);
    expect($service->listFiles($user, 'Poll'))->toBe([]);
    expect($service->listFiles($user, 'Poll'))->toBe([]); // cache hit
    expect($service->listFiles($user, 'Poll', forceRefresh: true)[0]['file'])->toBe('gast.pdf');
});

it('zeigt neu hochgeladene dateien sofort trotz cache', function (): void {
    config(['intranet-app-cloudshare.graph_cache_seconds' => 60]);
    $user = cloudshareUser();
    actingAs($user);

    CloudshareShare::query()->create([
        'user_id' => $user->id,
        'onedrive_item_id' => 'share-upload',
        'folder_name' => 'Upload',
        'password' => null,
    ]);

    $uploaded = Mockery::mock();
    $uploaded->shouldReceive('getName')->andReturn('neu.pdf');
    $uploaded->shouldReceive('getWebUrl')->andReturn('https://example.com/neu.pdf');
    $uploaded->shouldReceive('getLastModifiedDateTime')->andReturn(null);
    $uploaded->shouldReceive('getSize')->andReturn(42);
    $uploaded->shouldReceive('getId')->andReturn('file-neu');

    $oneDrive = mockCloudshareOneDrive();
    $oneDrive->shouldReceive('getUserDriveContent')
        ->twice()
        ->with(Mockery::type('string'), 'Cloudshare/Upload')
        ->andReturn([], [$uploaded]);
    $oneDrive->shouldReceive('uploadItemToUserDrive')->once()->andReturn(true);

    $service = app(CloudshareService::class);
    expect($service->listFiles($user, 'Upload'))->toBe([]);

    $service->uploadFile($user, 'Upload', '/tmp/fake', 'neu.pdf');

    expect($service->listFiles($user, 'Upload')[0]['file'])->toBe('neu.pdf');
});
