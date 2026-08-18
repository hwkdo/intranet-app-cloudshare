<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{file: string, href: string, modified: string, size: int|string, id: string} $resource
 */
class ApiShareFileResource extends JsonResource
{
    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     href: string,
     *     modified: string,
     *     size: int
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var array{file: string, href: string, modified: string, size: int|string, id: string} $file */
        $file = $this->resource;

        return [
            'id' => (string) $file['id'],
            'name' => (string) $file['file'],
            'href' => (string) $file['href'],
            'modified' => (string) $file['modified'],
            'size' => is_numeric($file['size']) ? (int) $file['size'] : 0,
        ];
    }
}
