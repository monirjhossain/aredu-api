<?php

namespace App\Http\Requests\MockTest;

use Illuminate\Foundation\Http\FormRequest;

class ReadingMockTestPublicationRequest extends FormRequest
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
            'is_published' => ['required', 'boolean'],
        ];
    }
}
