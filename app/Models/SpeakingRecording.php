<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SpeakingRecording extends Model
{
    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_SUBMITTED = 'submitted';

    public const CONTEXT_PRACTICE = 'practice';

    protected $fillable = [
        'speaking_task_id',
        'user_id',
        'disk',
        'path',
        'mime',
        'size_bytes',
        'duration_seconds',
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
        return $this->belongsTo(SpeakingTask::class, 'speaking_task_id');
    }

    public function evaluation(): HasOne
    {
        return $this->hasOne(SpeakingEvaluation::class);
    }
}
