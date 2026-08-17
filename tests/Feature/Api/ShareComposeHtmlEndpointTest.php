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

it('liefert die gerenderte share-mail als html', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $share = cloudshareSampleShare();
    $html = '<html><body>Cloudshare Freigabe</body></html>';

    $mock = mock(CloudshareServiceInterface::class);
    $mock->shouldReceive('findShare')
        ->once()
        ->andReturn($share);
    $mock->shouldReceive('previewShareMail')
        ->once()
        ->andReturn($html);

    $this->postJson('/api/cloudshare/shares/item-123/compose-html')
        ->assertOk()
        ->assertJsonPath('html', $html)
        ->assertJsonPath('subject', CloudshareSharedMail::DEFAULT_SUBJECT);
});
