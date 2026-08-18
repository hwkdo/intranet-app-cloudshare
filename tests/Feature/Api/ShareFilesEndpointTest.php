<?php

declare(strict_types=1);

use App\Models\User;
use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Illuminate\Http\UploadedFile;
use Laravel\Passport\Passport;

use function Pest\Laravel\mock;

it('liefert 401 wenn kein bearer token gesendet wird', function (): void {
    $this->postJson('/api/cloudshare/shares/item-123/files', [])
        ->assertUnauthorized();
});

it('liefert 401 wenn dateien ohne bearer token gelesen werden', function (): void {
    $this->getJson('/api/cloudshare/shares/item-123/files')
        ->assertUnauthorized();
});

it('liefert 404 wenn die freigabe nicht existiert', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);

    mock(CloudshareServiceInterface::class)
        ->shouldReceive('findShare')
        ->once()
        ->andReturn(null);

    $file = UploadedFile::fake()->create('dokument.pdf', 100);

    $this->post('/api/cloudshare/shares/item-missing/files', [
        'file' => $file,
    ], [
        'Accept' => 'application/json',
    ])->assertNotFound();
});

it('laedt eine datei in eine bestehende freigabe hoch', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $share = cloudshareSampleShare();
    $file = UploadedFile::fake()->create('test.pdf', 100);

    $mock = mock(CloudshareServiceInterface::class);
    $mock->shouldReceive('findShare')
        ->once()
        ->andReturn($share);
    $mock->shouldReceive('uploadFile')
        ->once()
        ->withArgs(function (mixed $uploadedBy, string $folderName, string $path, string $filename) use ($share): bool {
            return $folderName === $share['name'] && $filename === 'test.pdf';
        })
        ->andReturn(true);

    $this->post('/api/cloudshare/shares/item-123/files', [
        'file' => $file,
    ], [
        'Accept' => 'application/json',
    ])
        ->assertCreated()
        ->assertJsonPath('file', 'test.pdf');
});

it('liefert 404 wenn dateien einer unbekannten freigabe gelesen werden', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);

    mock(CloudshareServiceInterface::class)
        ->shouldReceive('findShare')
        ->once()
        ->andReturn(null);

    $this->getJson('/api/cloudshare/shares/item-missing/files')
        ->assertNotFound();
});

it('listet die dateien einer bestehenden freigabe', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $share = cloudshareSampleShare();

    $mock = mock(CloudshareServiceInterface::class);
    $mock->shouldReceive('findShare')
        ->once()
        ->andReturn($share);
    $mock->shouldReceive('listFiles')
        ->once()
        ->andReturn([
            [
                'file' => 'angebot.pdf',
                'href' => 'https://1drv.ms/angebot',
                'modified' => '18.08.2026 10:00',
                'size' => 128000,
                'id' => 'file-1',
            ],
        ]);

    $this->getJson('/api/cloudshare/shares/item-123/files')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', 'file-1')
        ->assertJsonPath('data.0.name', 'angebot.pdf')
        ->assertJsonPath('data.0.size', 128000);
});

it('liefert 401 wenn dateien ohne bearer token geloescht werden', function (): void {
    $this->deleteJson('/api/cloudshare/shares/item-123/files/file-1')
        ->assertUnauthorized();
});

it('liefert 404 wenn eine datei in einer unbekannten freigabe geloescht wird', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $mock = mock(CloudshareServiceInterface::class);
    $mock->shouldReceive('findShare')
        ->once()
        ->andReturn(null);
    $mock->shouldNotReceive('deleteItem');

    $this->deleteJson('/api/cloudshare/shares/item-missing/files/file-1')
        ->assertNotFound();
});

it('liefert 404 wenn die datei nicht zur freigabe gehoert', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $share = cloudshareSampleShare();

    $mock = mock(CloudshareServiceInterface::class);
    $mock->shouldReceive('findShare')
        ->once()
        ->andReturn($share);
    $mock->shouldReceive('listFiles')
        ->once()
        ->andReturn([
            [
                'file' => 'angebot.pdf',
                'href' => 'https://1drv.ms/angebot',
                'modified' => '18.08.2026 10:00',
                'size' => 128000,
                'id' => 'file-1',
            ],
        ]);
    $mock->shouldNotReceive('deleteItem');

    $this->deleteJson('/api/cloudshare/shares/item-123/files/file-other')
        ->assertNotFound();
});

it('loescht eine datei aus einer bestehenden freigabe', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $share = cloudshareSampleShare();

    $mock = mock(CloudshareServiceInterface::class);
    $mock->shouldReceive('findShare')
        ->once()
        ->andReturn($share);
    $mock->shouldReceive('listFiles')
        ->once()
        ->andReturn([
            [
                'file' => 'angebot.pdf',
                'href' => 'https://1drv.ms/angebot',
                'modified' => '18.08.2026 10:00',
                'size' => 128000,
                'id' => 'file-1',
            ],
        ]);
    $mock->shouldReceive('deleteItem')
        ->once()
        ->withArgs(fn (mixed $deletedBy, string $itemId): bool => $itemId === 'file-1')
        ->andReturn(true);

    $this->deleteJson('/api/cloudshare/shares/item-123/files/file-1')
        ->assertOk()
        ->assertJsonPath('message', 'Datei wurde gelöscht.');
});
