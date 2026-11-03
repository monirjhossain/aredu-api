<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseCategory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
    ];

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'category_id');
    }
}
