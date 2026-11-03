<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class CourseIndexRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:100'],
            'exam_type' => ['nullable', 'in:academic,general'],
            'category_id' => ['nullable', 'integer'],
            'is_published' => ['nullable', 'in:0,1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
