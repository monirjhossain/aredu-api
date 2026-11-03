<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionOption extends Model
{
    protected $fillable = [
        'question_group_id',
        'label',
        'content',
        'position',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(QuestionGroup::class, 'question_group_id');
    }
}
