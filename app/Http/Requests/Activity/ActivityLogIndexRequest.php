<?php

namespace App\Http\Requests\Activity;

use Illuminate\Foundation\Http\FormRequest;

class ActivityLogIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('settings.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
