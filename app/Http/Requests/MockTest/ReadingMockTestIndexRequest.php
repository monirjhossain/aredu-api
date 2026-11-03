<?php

namespace App\Http\Requests\MockTest;

use Illuminate\Foundation\Http\FormRequest;

class ReadingMockTestIndexRequest extends FormRequest
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
            'is_published' => ['nullable'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
