<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Hwkdo\IntranetAppCloudshare\Http\Controllers\Api\Concerns\ResolvesCloudshareShare;
use Hwkdo\IntranetAppCloudshare\Http\Requests\Api\StoreBitwardenSendRequest;
use Illuminate\Http\JsonResponse;

class ShareBitwardenSendController extends Controller
{
    use ResolvesCloudshareShare;

    public function store(
        StoreBitwardenSendRequest $request,
        string $share,
        CloudshareServiceInterface $cloudshare,
    ): JsonResponse {
        $resolved = $this->resolveShare($cloudshare, $request->user(), $share);

        if (! $resolved['has_stored_password']) {
            return response()->json([
                'bitwarden_sent' => false,
                'bitwarden_error' => 'Kein hinterlegtes Passwort für diese Freigabe.',
            ], 422);
        }

        $result = $cloudshare->sendPasswordViaBitwarden(
            $request->user(),
            $resolved,
            $request->validated('email'),
        );

        if (! $result['bitwarden_sent']) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }
}
