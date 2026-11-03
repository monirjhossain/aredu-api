<?php

namespace App\Http\Requests\Catalog;

use App\Models\CourseCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('categories.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $categoryId = $this->route('category') instanceof CourseCategory
            ? $this->route('category')->id
            : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('course_categories', 'slug')->ignore($categoryId),
            ],
        ];
    }
}
