<?php

namespace App\Http\Requests\MockTest;

use Illuminate\Foundation\Http\FormRequest;

class ReadingSectionRequest extends FormRequest
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
            'position' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
