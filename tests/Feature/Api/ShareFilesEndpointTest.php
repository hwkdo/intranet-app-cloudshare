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
    $file = UploadedFile::fake()->create('dokument.pdf', 100);

    $mock = mock(CloudshareServiceInterface::class);
    $mock->shouldReceive('findShare')
        ->once()
        ->andReturn($share);
    $mock->shouldReceive('uploadFile')
        ->once()
        ->andReturn(true);

    $this->post('/api/cloudshare/shares/item-123/files', [
        'file' => $file,
    ], [
        'Accept' => 'application/json',
    ])
        ->assertCreated()
        ->assertJsonPath('file', 'dokument.pdf');
});
