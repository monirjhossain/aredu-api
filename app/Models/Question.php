<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    protected $fillable = [
        'question_group_id',
        'prompt',
        'position',
        'marks',
        'correct_option_id',
    ];

    protected function casts(): array
    {
        return [
            'marks' => 'decimal:2',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(QuestionGroup::class, 'question_group_id');
    }

    public function correctOption(): BelongsTo
    {
        return $this->belongsTo(QuestionOption::class, 'correct_option_id');
    }

    public function acceptedAnswers(): HasMany
    {
        return $this->hasMany(QuestionAcceptedAnswer::class);
    }
}
