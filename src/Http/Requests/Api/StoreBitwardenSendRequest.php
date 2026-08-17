<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreBitwardenSendRequest extends FormRequest
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
            'email' => ['required', 'email'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'Empfänger',
        ];
    }
}
