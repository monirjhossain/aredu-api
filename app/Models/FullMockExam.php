<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FullMockExam extends Model
{
    use SoftDeletes;

    public const TYPE_ACADEMIC = 'academic';

    public const TYPE_GENERAL = 'general';

    protected $fillable = [
        'exam_type',
        'title',
        'slug',
        'instructions',
        'is_published',
        'is_free',
        'allow_resume',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'is_free' => 'boolean',
            'allow_resume' => 'boolean',
        ];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(FullMockExamSection::class)->orderBy('position')->orderBy('id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(FullMockSession::class);
    }
}
