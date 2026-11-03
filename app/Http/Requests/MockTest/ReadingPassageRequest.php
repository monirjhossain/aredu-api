<?php

namespace App\Http\Requests\MockTest;

use Illuminate\Foundation\Http\FormRequest;

class ReadingPassageRequest extends FormRequest
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
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'position' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
