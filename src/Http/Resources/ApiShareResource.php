<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{
 *     name: string,
 *     id: string,
 *     url: string,
 *     created_at: string,
 *     password: bool,
 *     has_stored_password: bool,
 *     expiration: ?string,
 *     writeable: bool,
 *     file_count: int
 * } $resource
 */
class ApiShareResource extends JsonResource
{
    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     url: string,
     *     created_at: string,
     *     password: bool,
     *     has_stored_password: bool,
     *     expiration: ?string,
     *     writeable: bool,
     *     file_count: int
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var array{name: string, id: string, url: string, created_at: string, password: bool, has_stored_password: bool, expiration: ?string, writeable: bool, file_count?: int} $share */
        $share = $this->resource;

        return [
            'id' => $share['id'],
            'name' => $share['name'],
            'url' => $share['url'],
            'created_at' => $share['created_at'],
            'password' => $share['password'],
            'has_stored_password' => $share['has_stored_password'],
            'expiration' => $share['expiration'],
            'writeable' => $share['writeable'],
            'file_count' => (int) ($share['file_count'] ?? 0),
        ];
    }
}
