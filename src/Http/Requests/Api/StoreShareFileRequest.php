<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreShareFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.(int) config('intranet-app-cloudshare.max_upload_kb', 256000),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'file' => 'Datei',
        ];
    }
}
