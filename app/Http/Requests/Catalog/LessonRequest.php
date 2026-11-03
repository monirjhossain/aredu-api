<?php

namespace App\Http\Requests\Catalog;

use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;

class LessonRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'duration_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'is_free_preview' => ['required', 'boolean'],
            'is_published' => ['required', 'boolean'],
        ];
    }
}
