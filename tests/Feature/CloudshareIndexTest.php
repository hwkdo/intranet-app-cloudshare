<?php

declare(strict_types=1);

use App\Models\User;
use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function (): void {
    Permission::findOrCreate('see-app-cloudshare', 'web');
    Permission::findOrCreate('manage-app-cloudshare', 'web');
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
        ->assertSee('https://example.com/demo')
        ->assertSee('Freigaben');
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
