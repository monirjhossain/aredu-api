<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'total' => $this->total,
            'currency' => $this->currency,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'course_id' => $item->course_id,
                'unit_price' => $item->unit_price,
                'discount_price' => $item->discount_price,
                'line_total' => $item->lineTotal(),
                'course' => $item->relationLoaded('course') && $item->course ? [
                    'id' => $item->course->id,
                    'title' => $item->course->title,
                    'slug' => $item->course->slug,
                ] : null,
                'course_title' => $item->relationLoaded('course') ? $item->course?->title : null,
            ])->values()),
            'payments' => $this->whenLoaded('payments', fn () => PaymentResource::collection($this->payments)->resolve()),
        ];
    }
}
