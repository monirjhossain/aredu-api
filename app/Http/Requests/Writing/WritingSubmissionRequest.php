<?php

namespace App\Http\Requests\Writing;

use Illuminate\Foundation\Http\FormRequest;

class WritingSubmissionRequest extends FormRequest
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
            'body' => ['nullable', 'string', 'max:50000'],
        ];
    }
}
