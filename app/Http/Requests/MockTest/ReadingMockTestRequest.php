<?php

namespace App\Http\Requests\MockTest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReadingMockTestRequest extends FormRequest
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
            'slug' => ['nullable', 'string', 'max:255'],
            'exam_type' => ['required', Rule::in(['academic', 'general'])],
            'instructions' => ['nullable', 'string'],
            'duration_seconds' => ['required', 'integer', 'min:60', 'max:18000'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }
}
