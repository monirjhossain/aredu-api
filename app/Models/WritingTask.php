<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WritingTask extends Model
{
    use SoftDeletes;

    public const TYPE_ACADEMIC_TASK_1 = 'academic_task_1';

    public const TYPE_GENERAL_TASK_1 = 'general_task_1';

    public const TYPE_TASK_2 = 'task_2';

    public const TYPES = [
        self::TYPE_ACADEMIC_TASK_1,
        self::TYPE_GENERAL_TASK_1,
        self::TYPE_TASK_2,
    ];

    protected $fillable = [
        'exam_type',
        'task_type',
        'title',
        'instructions',
        'prompt',
        'image_path',
        'recommended_minutes',
        'min_words',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
        ];
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(WritingSubmission::class);
    }
}
