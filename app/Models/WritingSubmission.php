<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WritingSubmission extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const CONTEXT_PRACTICE = 'practice';

    protected $fillable = [
        'writing_task_id',
        'user_id',
        'body',
        'word_count',
        'status',
        'context_key',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(WritingTask::class, 'writing_task_id');
    }

    public function evaluation(): HasOne
    {
        return $this->hasOne(WritingEvaluation::class);
    }
}
