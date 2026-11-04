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
        'learners_count',
        'class_starts_on',
        'batch_number',
        'seats_left',
        'offer_ends_on',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'discount_price' => 'decimal:2',
            'is_published' => 'boolean',
            'class_starts_on' => 'date',
            'offer_ends_on' => 'date',
            'learners_count' => 'integer',
            'batch_number' => 'integer',
            'seats_left' => 'integer',
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
