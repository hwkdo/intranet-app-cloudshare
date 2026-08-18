<?php

declare(strict_types=1);

use App\Models\User;
use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Laravel\Passport\Passport;

use function Pest\Laravel\mock;

it('liefert 401 wenn kein bearer token gesendet wird', function (): void {
    $this->getJson('/api/cloudshare/shares')
        ->assertUnauthorized();
});

it('liefert die shares des authentifizierten benutzers', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $share = cloudshareSampleShare();

    mock(CloudshareServiceInterface::class)
        ->shouldReceive('listShares')
        ->once()
        ->andReturn([$share]);

    $this->getJson('/api/cloudshare/shares')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', 'item-123')
        ->assertJsonPath('data.0.name', 'Projekt-X')
        ->assertJsonPath('data.0.has_stored_password', true)
        ->assertJsonPath('data.0.file_count', 2);
});

it('erstellt eine neue freigabe', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $expiresAt = now()->addDay()->format('Y-m-d\TH:i');
    $created = cloudshareSampleShare([
        'name' => 'Neue Freigabe',
        'id' => 'item-new',
        'password' => false,
        'has_stored_password' => false,
    ]);

    mock(CloudshareServiceInterface::class)
        ->shouldReceive('createShare')
        ->once()
        ->andReturn($created);

    $this->postJson('/api/cloudshare/shares', [
        'name' => 'Neue Freigabe',
        'expires_at' => $expiresAt,
        'guest_upload' => false,
    ])
        ->assertCreated()
        ->assertJsonPath('data.id', 'item-new')
        ->assertJsonPath('data.name', 'Neue Freigabe');
});

it('validiert pflichtfelder beim anlegen', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->postJson('/api/cloudshare/shares', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'expires_at']);
});
