<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionPassage extends Model
{
    protected $fillable = [
        'mock_test_section_id',
        'title',
        'body',
        'position',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(MockTestSection::class, 'mock_test_section_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(QuestionGroup::class);
    }
}
