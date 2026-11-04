<?php

namespace App\Http\Requests\Catalog;

use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('courses.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('discount_price') === '') {
            $this->merge(['discount_price' => null]);
        }

        if ($this->input('description') === '') {
            $this->merge(['description' => null]);
        }

        foreach (['learners_count', 'batch_number', 'seats_left', 'class_starts_on', 'offer_ends_on'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $courseId = $this->route('course') instanceof Course ? $this->route('course')->id : null;

        return [
            'category_id' => [
                'required',
                'integer',
                Rule::exists('course_categories', 'id')->whereNull('deleted_at'),
            ],
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('courses', 'slug')->ignore($courseId),
            ],
            'description' => ['nullable', 'string'],
            'exam_type' => ['required', 'in:academic,general'],
            'price' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:99999999.99'],
            'discount_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'lt:price'],
            'duration_minutes' => ['required', 'integer', 'min:0', 'max:100000'],
            'learners_count' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'class_starts_on' => ['nullable', 'date'],
            'batch_number' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'seats_left' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'offer_ends_on' => ['nullable', 'date'],
            'is_published' => ['required', 'boolean'],
        ];
    }
}
