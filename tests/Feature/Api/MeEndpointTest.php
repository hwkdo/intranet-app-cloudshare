<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Passport\Passport;

it('liefert 401 wenn kein bearer token gesendet wird', function (): void {
    $this->getJson('/api/cloudshare/me')
        ->assertUnauthorized();
});

it('liefert den authentifizierten benutzer', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->getJson('/api/cloudshare/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.name', $user->name);
});
