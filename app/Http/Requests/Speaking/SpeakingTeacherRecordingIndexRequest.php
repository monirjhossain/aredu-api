<?php

namespace App\Http\Requests\Speaking;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SpeakingTeacherRecordingIndexRequest extends FormRequest
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
            'evaluation_status' => ['nullable', Rule::in(['pending', 'evaluated'])],
            'context' => ['nullable', Rule::in(['all', 'practice', 'full_mock'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
