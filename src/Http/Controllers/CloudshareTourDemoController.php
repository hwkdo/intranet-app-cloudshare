<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Http\Controllers;

use Hwkdo\IntranetAppCloudshare\Support\CloudshareTourDemo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CloudshareTourDemoController
{
    public function enable(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('see-app-cloudshare'), 403);

        CloudshareTourDemo::enable();

        return response()->json(['ok' => true, 'demo' => true]);
    }

    public function disable(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('see-app-cloudshare'), 403);

        CloudshareTourDemo::disable();

        return response()->json(['ok' => true, 'demo' => false]);
    }
}
