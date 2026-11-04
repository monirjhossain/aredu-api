<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    public const CATEGORY_RENT = 'rent';

    public const CATEGORY_SALARY = 'salary';

    public const CATEGORY_MARKETING = 'marketing';

    public const CATEGORY_TOOLS = 'tools';

    public const CATEGORY_UTILITY = 'utility';

    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_RENT,
        self::CATEGORY_SALARY,
        self::CATEGORY_MARKETING,
        self::CATEGORY_TOOLS,
        self::CATEGORY_UTILITY,
        self::CATEGORY_OTHER,
    ];

    protected $fillable = [
        'title',
        'category',
        'amount',
        'currency',
        'spent_on',
        'payment_method',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'spent_on' => 'date',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
