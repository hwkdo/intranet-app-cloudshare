<?php

declare(strict_types=1);

use App\Models\User;
use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Hwkdo\IntranetAppCloudshare\Mail\CloudshareSharedMail;
use Laravel\Passport\Passport;

use function Pest\Laravel\mock;

it('liefert 401 wenn kein bearer token gesendet wird', function (): void {
    $this->postJson('/api/cloudshare/shares/item-123/compose-html')
        ->assertUnauthorized();
});

it('liefert ein kompaktes outlook-html ohne mail-layout', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $share = cloudshareSampleShare();

    mock(CloudshareServiceInterface::class)
        ->shouldReceive('findShare')
        ->once()
        ->andReturn($share);

    $response = $this->postJson('/api/cloudshare/shares/item-123/compose-html')
        ->assertOk()
        ->assertJsonPath('subject', CloudshareSharedMail::subjectForShare('Projekt-X'));

    $html = (string) $response->json('html');

    expect($html)
        ->toContain('alt="Cloud Share"')
        ->toContain(CloudshareSharedMail::LOGO_URL)
        ->toContain('Projekt-X')
        ->toContain('Passwortschutz: aktiviert')
        ->toContain('Gültig bis: 31.12.2026 23:59 Uhr')
        ->toContain('Gast-Upload: nicht aktiviert')
        ->toContain('Zur Freigabe')
        ->toContain('https://1drv.ms/example')
        ->not->toContain('hat den Cloud-Ordner')
        ->not->toContain('Bei Rückfragen')
        ->not->toContain('Handwerkskammer Dortmund');
});
