<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const INITIATED = 'initiated';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const REFUNDED = 'refunded';

    public const GATEWAY_SSLCOMMERZ = 'sslcommerz';

    public const GATEWAY_BKASH = 'bkash';

    /** Zero-total orders (free course or 100% coupon) — no external gateway. */
    public const GATEWAY_FREE = 'free';

    protected $fillable = [
        'order_id',
        'gateway',
        'status',
        'amount',
        'currency',
        'gateway_transaction_id',
        'gateway_reference',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
