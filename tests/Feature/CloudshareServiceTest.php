<?php

declare(strict_types=1);

use App\Models\User;
use Hwkdo\HwkAdminLaravel\HwkAdminService;
use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Hwkdo\IntranetAppCloudshare\Data\AppSettings;
use Hwkdo\IntranetAppCloudshare\Mail\CloudsharePasswordSendMail;
use Hwkdo\IntranetAppCloudshare\Mail\CloudshareSharedMail;
use Hwkdo\IntranetAppCloudshare\Models\CloudshareShare;
use Hwkdo\IntranetAppCloudshare\Models\IntranetAppCloudshareSettings;
use Hwkdo\IntranetAppCloudshare\Services\CloudshareService;
use Hwkdo\MsGraphLaravel\Interfaces\MsGraphOneDriveServiceInterface;
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

    if (! Schema::hasTable('intranet_app_cloudshare_shares')) {
        Schema::create('intranet_app_cloudshare_shares', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('onedrive_item_id');
            $table->string('folder_name');
            $table->text('password');
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

it('listet freigaben über den oneDrive service', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    $folder = Mockery::mock();
    $folder->shouldReceive('getFolder')->andReturn((object) []);
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

    $oneDrive = mock(MsGraphOneDriveServiceInterface::class);
    $oneDrive->shouldReceive('makeFolder')->once()->with(Mockery::type('string'), 'Cloudshare')->andReturn($folder);
    $oneDrive->shouldReceive('getUserDriveContent')->once()->with(Mockery::type('string'), 'Cloudshare')->andReturn([$folder]);
    $oneDrive->shouldReceive('getDriveItemPermissions')->once()->with(Mockery::type('string'), 'folder-1', 'anonymous')->andReturn(collect([$perm]));

    $shares = app(CloudshareService::class)->listShares($user);

    expect($shares)->toHaveCount(1)
        ->and($shares[0]['name'])->toBe('Projekt')
        ->and($shares[0]['url'])->toBe('https://example.com/share')
        ->and($shares[0]['password'])->toBeTrue()
        ->and($shares[0]['has_stored_password'])->toBeFalse()
        ->and($shares[0]['writeable'])->toBeTrue();
});

it('holt den freigabelink per createLink wenn list-permissions keine webUrl liefert', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    $folder = Mockery::mock();
    $folder->shouldReceive('getFolder')->andReturn((object) []);
    $folder->shouldReceive('getShared')->andReturn((object) []);
    $folder->shouldReceive('getId')->andReturn('folder-1');
    $folder->shouldReceive('getName')->andReturn('Projekt');
    $folder->shouldReceive('getFileSystemInfo')->andReturn(null);

    $link = Mockery::mock();
    $link->shouldReceive('getWebUrl')->andReturn(null);

    $perm = Mockery::mock();
    $perm->shouldReceive('getLink')->andReturn($link);
    $perm->shouldReceive('getExpirationDateTime')->andReturn(null);
    $perm->shouldReceive('getHasPassword')->andReturn(false);
    $perm->shouldReceive('getRoles')->andReturn(['read']);

    $oneDrive = mock(MsGraphOneDriveServiceInterface::class);
    $oneDrive->shouldReceive('makeFolder')->once()->with(Mockery::type('string'), 'Cloudshare')->andReturn($folder);
    $oneDrive->shouldReceive('getUserDriveContent')->once()->with(Mockery::type('string'), 'Cloudshare')->andReturn([$folder]);
    $oneDrive->shouldReceive('getDriveItemPermissions')->once()->with(Mockery::type('string'), 'folder-1', 'anonymous')->andReturn(collect([$perm]));
    $oneDrive->shouldReceive('shareReadOnly')->once()->with(Mockery::type('string'), 'folder-1')->andReturn('https://example.com/recovered-share');
    $oneDrive->shouldNotReceive('shareReadWrite');

    $shares = app(CloudshareService::class)->listShares($user);

    expect($shares)->toHaveCount(1)
        ->and($shares[0]['url'])->toBe('https://example.com/recovered-share');
});

it('holt den schreibbaren freigabelink per createLink wenn webUrl fehlt', function (): void {
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

    $oneDrive = mock(MsGraphOneDriveServiceInterface::class);
    $oneDrive->shouldReceive('makeFolder')->once()->andReturn($folder);
    $oneDrive->shouldReceive('getUserDriveContent')->once()->andReturn([$folder]);
    $oneDrive->shouldReceive('getDriveItemPermissions')->once()->andReturn(collect([$perm]));
    $oneDrive->shouldReceive('shareReadWrite')->once()->with(Mockery::type('string'), 'folder-2')->andReturn('https://example.com/rw-share');
    $oneDrive->shouldNotReceive('shareReadOnly');

    $shares = app(CloudshareService::class)->listShares($user);

    expect($shares)->toHaveCount(1)
        ->and($shares[0]['url'])->toBe('https://example.com/rw-share')
        ->and($shares[0]['writeable'])->toBeTrue();
});

it('erstellt eine freigabe und speichert passwort verschluesselt', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    $createdFolder = Mockery::mock();
    $createdFolder->shouldReceive('getId')->andReturn('new-folder-id');

    $oneDrive = mock(MsGraphOneDriveServiceInterface::class);
    $oneDrive->shouldReceive('makeFolder')
        ->once()
        ->with(Mockery::type('string'), 'Cloudshare/Demo')
        ->andReturn($createdFolder);
    $oneDrive->shouldReceive('shareReadWrite')
        ->once()
        ->with(Mockery::type('string'), 'new-folder-id', 'secret123', Mockery::type('string'))
        ->andReturn('https://example.com/rw');

    $ok = app(CloudshareService::class)->createShare($user, [
        'name' => 'Demo',
        'password' => 'secret123',
        'expires_at' => now()->addDay()->toDateTimeString(),
        'guest_upload' => true,
    ]);

    expect($ok)->toBeTrue();

    $row = DB::table('intranet_app_cloudshare_shares')->where('onedrive_item_id', 'new-folder-id')->first();
    expect($row)->not->toBeNull()
        ->and($row->password)->not->toBe('secret123');

    $model = CloudshareShare::query()->where('onedrive_item_id', 'new-folder-id')->first();
    expect($model)->not->toBeNull()
        ->and($model->password)->toBe('secret123');
});

it('validiert passwortlänge bei createShare', function (): void {
    $user = cloudshareUser();
    actingAs($user);

    mock(MsGraphOneDriveServiceInterface::class)->shouldNotReceive('makeFolder');

    app(CloudshareService::class)->createShare($user, [
        'name' => 'Demo',
        'password' => 'short',
        'expires_at' => now()->addDay()->toDateTimeString(),
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

    $oneDrive = mock(MsGraphOneDriveServiceInterface::class);
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

    mock(MsGraphOneDriveServiceInterface::class)
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

    mock(MsGraphOneDriveServiceInterface::class);

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

    mock(MsGraphOneDriveServiceInterface::class);
    mock(HwkAdminService::class)
        ->shouldReceive('createBitwardenSend')
        ->once()
        ->with('Cloudshare: Demo', 'secret123', 1, 7)
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

    mock(MsGraphOneDriveServiceInterface::class);
    mock(HwkAdminService::class)
        ->shouldReceive('createBitwardenSend')
        ->once()
        ->with('Cloudshare: Demo', 'secret123', 3, 14)
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

    mock(MsGraphOneDriveServiceInterface::class);

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
