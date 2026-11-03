<?php

namespace App\Http\Requests\Speaking;

use Illuminate\Foundation\Http\FormRequest;

class SpeakingQuestionRequest extends FormRequest
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
            'cue_card' => ['nullable', 'string'],
            'position' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
