<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category_id',
        'title',
        'slug',
        'description',
        'thumbnail_path',
        'exam_type',
        'price',
        'discount_price',
        'duration_minutes',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'discount_price' => 'decimal:2',
            'is_published' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CourseCategory::class, 'category_id');
    }

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_teachers')
            ->withPivot('can_manage_content')
            ->withTimestamps();
    }

    public function sections(): HasMany
    {
        return $this->hasMany(CourseSection::class)->orderBy('position')->orderBy('id');
    }
}
