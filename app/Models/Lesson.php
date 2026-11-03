<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lesson extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'course_section_id',
        'title',
        'position',
        'duration_seconds',
        'is_free_preview',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'is_free_preview' => 'boolean',
            'is_published' => 'boolean',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'course_section_id');
    }

    public function video(): HasOne
    {
        return $this->hasOne(Video::class);
    }
}
