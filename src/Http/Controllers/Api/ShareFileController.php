<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Hwkdo\IntranetAppCloudshare\Contracts\CloudshareServiceInterface;
use Hwkdo\IntranetAppCloudshare\Http\Controllers\Api\Concerns\ResolvesCloudshareShare;
use Hwkdo\IntranetAppCloudshare\Http\Requests\Api\StoreShareFileRequest;
use Hwkdo\IntranetAppCloudshare\Http\Resources\ApiShareFileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;

class ShareFileController extends Controller
{
    use ResolvesCloudshareShare;

    public function index(
        Request $request,
        string $share,
        CloudshareServiceInterface $cloudshare,
    ): AnonymousResourceCollection {
        $resolved = $this->resolveShare($cloudshare, $request->user(), $share);

        return ApiShareFileResource::collection(
            $cloudshare->listFiles($request->user(), $resolved['name']),
        );
    }

    public function store(
        StoreShareFileRequest $request,
        string $share,
        CloudshareServiceInterface $cloudshare,
    ): JsonResponse {
        $resolved = $this->resolveShare($cloudshare, $request->user(), $share);
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            abort(422, 'Datei konnte nicht gelesen werden.');
        }

        $localPath = $file->getRealPath();

        if ($localPath === false || $localPath === '') {
            abort(422, 'Datei konnte nicht gelesen werden.');
        }

        $cloudshare->uploadFile(
            $request->user(),
            $resolved['name'],
            $localPath,
            $file->getClientOriginalName(),
        );

        return response()->json([
            'message' => 'Datei wurde hochgeladen.',
            'file' => $file->getClientOriginalName(),
        ], 201);
    }

    public function destroy(
        Request $request,
        string $share,
        string $file,
        CloudshareServiceInterface $cloudshare,
    ): JsonResponse {
        $resolved = $this->resolveShare($cloudshare, $request->user(), $share);
        $match = collect($cloudshare->listFiles($request->user(), $resolved['name']))
            ->first(fn (array $item): bool => (string) $item['id'] === $file);

        if (! is_array($match)) {
            abort(404, 'Datei nicht gefunden.');
        }

        $cloudshare->deleteItem($request->user(), (string) $match['id']);

        return response()->json([
            'message' => 'Datei wurde gelöscht.',
        ]);
    }
}
