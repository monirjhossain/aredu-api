<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpeakingEvaluation extends Model
{
    protected $fillable = [
        'speaking_recording_id',
        'teacher_id',
        'fluency',
        'lexical',
        'grammar',
        'pronunciation',
        'overall_band',
        'feedback',
        'evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'fluency' => 'float',
            'lexical' => 'float',
            'grammar' => 'float',
            'pronunciation' => 'float',
            'overall_band' => 'float',
            'evaluated_at' => 'datetime',
        ];
    }

    public function recording(): BelongsTo
    {
        return $this->belongsTo(SpeakingRecording::class, 'speaking_recording_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
