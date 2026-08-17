<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Hwkdo\IntranetAppCloudshare\Http\Controllers\Api\Concerns\ResolvesCloudshareShare;
use Hwkdo\IntranetAppCloudshare\Mail\CloudshareSharedMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShareComposeController extends Controller
{
    use ResolvesCloudshareShare;

    public function store(
        Request $request,
        string $share,
        CloudshareServiceInterface $cloudshare,
    ): JsonResponse {
        $resolved = $this->resolveShare($cloudshare, $request->user(), $share);
        $subject = CloudshareSharedMail::DEFAULT_SUBJECT;

        return response()->json([
            'html' => $cloudshare->previewShareMail($request->user(), $resolved, $subject),
            'subject' => $subject,
        ]);
    }
}
