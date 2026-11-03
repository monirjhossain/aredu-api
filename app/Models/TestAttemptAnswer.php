<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestAttemptAnswer extends Model
{
    protected $fillable = [
        'test_attempt_id',
        'question_id',
        'question_option_id',
        'answer_text',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(TestAttempt::class, 'test_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(QuestionOption::class, 'question_option_id');
    }
}
