<?php

namespace App\Http\Requests\Catalog;

use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;

class LessonOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $course = $this->route('course');

        return $course instanceof Course && ($this->user()?->can('manageContent', $course) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lesson_ids' => ['required', 'array'],
            'lesson_ids.*' => ['integer', 'distinct'],
        ];
    }
}
