<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Http\Controllers\Api\Concerns;

use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Illuminate\Contracts\Auth\Authenticatable;

trait ResolvesCloudshareShare
{
    /**
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
    protected function resolveShare(CloudshareServiceInterface $cloudshare, Authenticatable $user, string $id): array
    {
        $share = $cloudshare->findShare($user, $id);

        if ($share === null) {
            abort(404, 'Freigabe nicht gefunden.');
        }

        return $share;
    }
}
