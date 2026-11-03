<?php

namespace App\Http\Requests\MockTest;

use Illuminate\Foundation\Http\FormRequest;

class ReadingQuestionRequest extends FormRequest
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
            'prompt' => ['required', 'string'],
            'position' => ['nullable', 'integer', 'min:1'],
            'marks' => ['nullable', 'numeric', 'min:0.25', 'max:100'],
            'correct_option_id' => ['nullable', 'integer'],
            'accepted_answers' => ['nullable', 'array'],
            'accepted_answers.*' => ['nullable'],
        ];
    }
}
