<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class CourseTeachersRequest extends FormRequest
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
            'teachers' => ['present', 'array'],
            'teachers.*.user_id' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'teachers.*.can_manage_content' => ['required', 'boolean'],
        ];
    }
}
