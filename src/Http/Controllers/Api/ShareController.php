<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Hwkdo\IntranetAppCloudshare\Http\Requests\Api\StoreShareRequest;
use Hwkdo\IntranetAppCloudshare\Http\Resources\ApiShareResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ShareController extends Controller
{
    public function index(Request $request, CloudshareServiceInterface $cloudshare): AnonymousResourceCollection
    {
        return ApiShareResource::collection(
            $cloudshare->listShares($request->user()),
        );
    }

    public function store(StoreShareRequest $request, CloudshareServiceInterface $cloudshare): JsonResponse
    {
        $share = $cloudshare->createShare($request->user(), [
            'name' => $request->validated('name'),
            'password' => $request->validated('password'),
            'expires_at' => $request->validated('expires_at'),
            'guest_upload' => $request->boolean('guest_upload'),
        ]);

        return (new ApiShareResource($share))
            ->response()
            ->setStatusCode(201);
    }
}
