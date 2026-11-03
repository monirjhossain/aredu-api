<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MockTestSection extends Model
{
    protected $fillable = [
        'mock_test_id',
        'title',
        'position',
        'question_audio_id',
    ];

    public function test(): BelongsTo
    {
        return $this->belongsTo(MockTest::class, 'mock_test_id');
    }

    public function audio(): BelongsTo
    {
        return $this->belongsTo(QuestionAudio::class, 'question_audio_id');
    }

    public function passages(): HasMany
    {
        return $this->hasMany(QuestionPassage::class)->orderBy('position')->orderBy('id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(QuestionGroup::class)->orderBy('position')->orderBy('id');
    }
}
