<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppCloudshare\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreShareRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:200'],
            'password' => ['nullable', 'string', 'min:8'],
            'expires_at' => ['required', 'date', 'after:now'],
            'guest_upload' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'Name',
            'password' => 'Passwort',
            'expires_at' => 'Gültigkeit',
            'guest_upload' => 'Gast-Upload',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expires_at.after' => 'Die Gültigkeit muss in der Zukunft liegen.',
        ];
    }
}
