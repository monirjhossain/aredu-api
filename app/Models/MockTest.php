<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MockTest extends Model
{
    use SoftDeletes;

    public const MODULE_READING = 'reading';

    public const MODULE_LISTENING = 'listening';

    protected $fillable = [
        'module',
        'exam_type',
        'title',
        'slug',
        'instructions',
        'duration_seconds',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
        ];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(MockTestSection::class)->orderBy('position')->orderBy('id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(TestAttempt::class);
    }
}
