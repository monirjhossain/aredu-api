<?php

namespace App\Http\Requests\Authorization;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
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
        $roleId = $this->route('role') instanceof Role ? $this->route('role')->id : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('roles', 'slug')->ignore($roleId)],
        ];
    }
}
