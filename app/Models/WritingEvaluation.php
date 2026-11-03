<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WritingEvaluation extends Model
{
    protected $fillable = [
        'writing_submission_id',
        'teacher_id',
        'task_response',
        'coherence',
        'lexical',
        'grammar',
        'overall_band',
        'feedback',
        'evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'task_response' => 'float',
            'coherence' => 'float',
            'lexical' => 'float',
            'grammar' => 'float',
            'overall_band' => 'float',
            'evaluated_at' => 'datetime',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(WritingSubmission::class, 'writing_submission_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
