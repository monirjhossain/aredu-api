<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TestAttempt extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SUBMITTED = 'submitted';

    public const CONTEXT_PRACTICE = 'practice';

    protected $fillable = [
        'mock_test_id',
        'user_id',
        'started_at',
        'must_submit_at',
        'submitted_at',
        'status',
        'context_key',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'must_submit_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mockTest(): BelongsTo
    {
        return $this->belongsTo(MockTest::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(TestAttemptAnswer::class);
    }

    public function result(): HasOne
    {
        return $this->hasOne(TestResult::class);
    }
}
