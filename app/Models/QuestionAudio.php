<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class QuestionAudio extends Model
{
    protected $table = 'question_audios';

    protected $fillable = [
        'disk',
        'path',
        'duration_seconds',
        'original_name',
    ];

    public function section(): HasOne
    {
        return $this->hasOne(MockTestSection::class, 'question_audio_id');
    }
}
