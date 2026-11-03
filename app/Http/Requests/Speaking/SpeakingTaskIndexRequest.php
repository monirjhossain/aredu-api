<?php

namespace App\Http\Requests\Speaking;

use App\Models\SpeakingTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SpeakingTaskIndexRequest extends FormRequest
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
            'part' => ['nullable', Rule::in(SpeakingTask::PARTS)],
            'is_published' => ['nullable'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
