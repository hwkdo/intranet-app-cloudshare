<?php

declare(strict_types=1);
use Hwkdo\MsGraphLaravel\Interfaces\MsGraphDelegatedOneDriveFactoryInterface;
use Hwkdo\MsGraphLaravel\Interfaces\MsGraphOneDriveServiceInterface;

use function Pest\Laravel\mock;

if (! function_exists('cloudshareSampleShare')) {
    /**
     * @param  array<string, mixed>  $overrides
     * @return array{
     *     name: string,
     *     id: string,
     *     url: string,
     *     created_at: string,
     *     password: bool,
     *     has_stored_password: bool,
     *     expiration: ?string,
     *     writeable: bool,
     *     file_count: int
     * }
     */
    function cloudshareSampleShare(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Projekt-X',
            'id' => 'item-123',
            'url' => 'https://1drv.ms/example',
            'created_at' => '17.08.2026 10:00',
            'password' => true,
            'has_stored_password' => true,
            'expiration' => '31.12.2026 23:59 Uhr',
            'writeable' => false,
            'file_count' => 2,
        ], $overrides);
    }
}

if (! function_exists('mockCloudshareOneDrive')) {
    function mockCloudshareOneDrive(): MsGraphOneDriveServiceInterface
    {
        $oneDrive = mock(MsGraphOneDriveServiceInterface::class);
        mock(MsGraphDelegatedOneDriveFactoryInterface::class)
            ->shouldReceive('forUser')
            ->andReturn($oneDrive);

        return $oneDrive;
    }
}
