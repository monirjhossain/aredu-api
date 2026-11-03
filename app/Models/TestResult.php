<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestResult extends Model
{
    protected $fillable = [
        'test_attempt_id',
        'raw_score',
        'max_score',
        'band_score',
    ];

    protected function casts(): array
    {
        return [
            'raw_score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'band_score' => 'decimal:1',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(TestAttempt::class, 'test_attempt_id');
    }
}
