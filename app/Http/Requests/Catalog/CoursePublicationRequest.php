<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class CoursePublicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('courses.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_published' => ['required', 'boolean'],
        ];
    }
}
