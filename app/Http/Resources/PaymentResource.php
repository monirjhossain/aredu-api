<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $canManagePayments = $request->user()?->hasPermission('payments.manage') ?? false;

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'gateway' => $this->gateway,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'gateway_transaction_id' => $this->gateway_transaction_id,
            'gateway_reference' => $this->when($canManagePayments, $this->gateway_reference),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
            'order' => $this->whenLoaded('order', fn () => $this->order ? [
                'id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'status' => $this->order->status,
                'user' => $this->order->relationLoaded('user') && $this->order->user ? [
                    'id' => $this->order->user->id,
                    'name' => $this->order->user->name,
                    'email' => $this->order->user->email,
                    'phone' => $this->order->user->phone,
                ] : null,
            ] : null),
        ];
    }
}
