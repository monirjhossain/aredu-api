<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FullMockExamSection extends Model
{
    public const SKILL_LISTENING = 'listening';

    public const SKILL_READING = 'reading';

    public const SKILL_WRITING = 'writing';

    public const SKILL_SPEAKING = 'speaking';

    public const SKILLS = [
        self::SKILL_LISTENING,
        self::SKILL_READING,
        self::SKILL_WRITING,
        self::SKILL_SPEAKING,
    ];

    protected $fillable = [
        'full_mock_exam_id',
        'skill',
        'title',
        'position',
        'duration_seconds',
        'instructions',
        'mock_test_id',
        'writing_task_id',
        'speaking_task_id',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(FullMockExam::class, 'full_mock_exam_id');
    }

    public function mockTest(): BelongsTo
    {
        return $this->belongsTo(MockTest::class);
    }

    public function writingTask(): BelongsTo
    {
        return $this->belongsTo(WritingTask::class);
    }

    public function speakingTask(): BelongsTo
    {
        return $this->belongsTo(SpeakingTask::class);
    }
}
