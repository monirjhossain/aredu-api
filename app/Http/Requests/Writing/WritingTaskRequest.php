<?php

namespace App\Http\Requests\Writing;

use App\Models\WritingTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WritingTaskRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'exam_type' => ['required', Rule::in(['academic', 'general'])],
            'task_type' => ['required', Rule::in(WritingTask::TYPES)],
            'instructions' => ['nullable', 'string'],
            'prompt' => ['required', 'string'],
            'recommended_minutes' => ['required', 'integer', 'min:5', 'max:120'],
            'min_words' => ['required', 'integer', 'min:50', 'max:1000'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }
}
