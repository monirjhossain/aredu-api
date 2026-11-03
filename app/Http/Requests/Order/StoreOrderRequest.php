<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
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
            'course_ids' => ['required', 'array', 'min:1'],
            'course_ids.*' => ['integer', 'distinct', 'exists:courses,id'],
            'coupon_code' => ['nullable', 'string', 'max:50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Frontend must never dictate money. Drop any client price fields.
        $this->replace($this->only(['course_ids', 'coupon_code']));
    }
}
