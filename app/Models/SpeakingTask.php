<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SpeakingTask extends Model
{
    use SoftDeletes;

    public const PART_1 = 1;

    public const PART_2 = 2;

    public const PART_3 = 3;

    public const PARTS = [
        self::PART_1,
        self::PART_2,
        self::PART_3,
    ];

    protected $fillable = [
        'part',
        'title',
        'instructions',
        'preparation_seconds',
        'response_seconds',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'part' => 'integer',
            'preparation_seconds' => 'integer',
            'response_seconds' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    public function questions(): HasMany
    {
        return $this->hasMany(SpeakingQuestion::class)->orderBy('position')->orderBy('id');
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(SpeakingRecording::class);
    }
}
