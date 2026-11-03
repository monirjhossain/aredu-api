<?php

namespace App\Http\Requests\Progress;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLessonProgressRequest extends FormRequest
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
            'position_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
        ];
    }
}
