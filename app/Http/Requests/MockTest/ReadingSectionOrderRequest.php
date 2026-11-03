<?php

namespace App\Http\Requests\MockTest;

use Illuminate\Foundation\Http\FormRequest;

class ReadingSectionOrderRequest extends FormRequest
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
            'section_ids' => ['required', 'array', 'min:1'],
            'section_ids.*' => ['integer', 'distinct'],
        ];
    }
}
