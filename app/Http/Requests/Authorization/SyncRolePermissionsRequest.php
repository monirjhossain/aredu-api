<?php

namespace App\Http\Requests\Authorization;

use Illuminate\Foundation\Http\FormRequest;

class SyncRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('roles.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', 'exists:permissions,slug'],
        ];
    }
}
