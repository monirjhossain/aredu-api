<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'course_id',
        'unit_price',
        'discount_price',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'discount_price' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lineTotal(): string
    {
        $price = $this->discount_price !== null ? $this->discount_price : $this->unit_price;

        return number_format((float) $price, 2, '.', '');
    }
}
