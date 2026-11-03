<?php

namespace App\Http\Requests\Speaking;

use App\Models\SpeakingTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SpeakingTaskRequest extends FormRequest
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
            'part' => ['required', 'integer', Rule::in(SpeakingTask::PARTS)],
            'instructions' => ['nullable', 'string'],
            'preparation_seconds' => ['required', 'integer', 'min:0', 'max:600'],
            'response_seconds' => ['required', 'integer', 'min:1', 'max:900'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }
}
