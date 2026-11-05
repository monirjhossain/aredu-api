<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FullMockSessionSection extends Model
{
    public const STATUS_LOCKED = 'locked';

    public const STATUS_READY = 'ready';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'full_mock_session_id',
        'full_mock_exam_section_id',
        'skill',
        'title',
        'position',
        'duration_seconds',
        'status',
        'started_at',
        'must_submit_at',
        'completed_at',
        'test_attempt_id',
        'writing_submission_id',
        'speaking_recording_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'must_submit_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function contextKey(): string
    {
        return 'fmss:'.$this->id;
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(FullMockSession::class, 'full_mock_session_id');
    }

    public function examSection(): BelongsTo
    {
        return $this->belongsTo(FullMockExamSection::class, 'full_mock_exam_section_id');
    }

    public function testAttempt(): BelongsTo
    {
        return $this->belongsTo(TestAttempt::class);
    }

    public function writingSubmission(): BelongsTo
    {
        return $this->belongsTo(WritingSubmission::class);
    }

    public function speakingRecording(): BelongsTo
    {
        return $this->belongsTo(SpeakingRecording::class);
    }
}
