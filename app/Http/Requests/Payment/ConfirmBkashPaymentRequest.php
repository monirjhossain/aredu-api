<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmBkashPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('payments.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'trx_id' => ['required', 'string', 'min:4', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'trx_id.required' => 'TrxID is required to confirm bKash payment.',
            'trx_id.min' => 'Enter a valid TrxID.',
        ];
    }
}
