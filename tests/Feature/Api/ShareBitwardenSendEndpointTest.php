<?php

declare(strict_types=1);

use App\Models\User;
use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Laravel\Passport\Passport;

use function Pest\Laravel\mock;

it('liefert 401 wenn kein bearer token gesendet wird', function (): void {
    $this->postJson('/api/cloudshare/shares/item-123/bitwarden-send', [
        'email' => 'gast@example.com',
    ])->assertUnauthorized();
});

it('validiert die empfaenger-email', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->postJson('/api/cloudshare/shares/item-123/bitwarden-send', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('lehnt bitwarden send ohne hinterlegtes passwort ab', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);

    mock(CloudshareServiceInterface::class)
        ->shouldReceive('findShare')
        ->once()
        ->andReturn(cloudshareSampleShare([
            'has_stored_password' => false,
        ]));

    $this->postJson('/api/cloudshare/shares/item-123/bitwarden-send', [
        'email' => 'gast@example.com',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('bitwarden_sent', false);
});

it('sendet das passwort per bitwarden send', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $share = cloudshareSampleShare();

    $mock = mock(CloudshareServiceInterface::class);
    $mock->shouldReceive('findShare')
        ->once()
        ->andReturn($share);
    $mock->shouldReceive('sendPasswordViaBitwarden')
        ->once()
        ->andReturn([
            'bitwarden_sent' => true,
            'bitwarden_error' => null,
        ]);

    $this->postJson('/api/cloudshare/shares/item-123/bitwarden-send', [
        'email' => 'gast@example.com',
    ])
        ->assertOk()
        ->assertJsonPath('bitwarden_sent', true)
        ->assertJsonPath('bitwarden_error', null);
});
