<?php

namespace App\Http\Requests\Authorization;

use Illuminate\Foundation\Http\FormRequest;

class SyncUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $this->user() !== null
            && $target !== null
            && $this->user()->can('assignRoles', $target);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', 'exists:roles,slug'],
        ];
    }
}
