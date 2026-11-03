<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpeakingQuestion extends Model
{
    protected $fillable = [
        'speaking_task_id',
        'prompt',
        'cue_card',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(SpeakingTask::class, 'speaking_task_id');
    }
}
