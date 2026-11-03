<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const REFUNDED = 'refunded';

    protected $fillable = [
        'user_id',
        'order_number',
        'status',
        'subtotal',
        'discount_total',
        'total',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function couponUsages(): HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
