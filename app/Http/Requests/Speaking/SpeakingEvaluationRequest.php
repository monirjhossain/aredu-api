<?php

namespace App\Http\Requests\Speaking;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SpeakingEvaluationRequest extends FormRequest
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
            'fluency' => ['required', 'numeric', 'min:0', 'max:9'],
            'lexical' => ['required', 'numeric', 'min:0', 'max:9'],
            'grammar' => ['required', 'numeric', 'min:0', 'max:9'],
            'pronunciation' => ['required', 'numeric', 'min:0', 'max:9'],
            'overall_band' => ['required', 'numeric', 'min:0', 'max:9'],
            'feedback' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (['fluency', 'lexical', 'grammar', 'pronunciation', 'overall_band'] as $field) {
                if ($validator->errors()->has($field)) {
                    continue;
                }
                $value = $this->input($field);
                if ($value === null || $value === '') {
                    continue;
                }
                if (! $this->isHalfBand((float) $value)) {
                    $validator->errors()->add($field, 'Band scores must use whole or half bands between 0 and 9.');
                }
            }
        });
    }

    private function isHalfBand(float $value): bool
    {
        $scaled = $value * 2;

        return abs($scaled - round($scaled)) < 0.00001;
    }
}
