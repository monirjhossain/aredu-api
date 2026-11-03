<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PublicCourseIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'exam_type' => ['nullable', 'in:academic,general'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('course_categories', 'id')->whereNull('deleted_at'),
            ],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
