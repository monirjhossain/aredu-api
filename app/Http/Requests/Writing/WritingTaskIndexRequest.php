<?php

namespace App\Http\Requests\Writing;

use Illuminate\Foundation\Http\FormRequest;

class WritingTaskIndexRequest extends FormRequest
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
            'task_type' => ['nullable', 'string'],
            'is_published' => ['nullable'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
