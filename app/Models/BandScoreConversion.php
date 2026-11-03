<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BandScoreConversion extends Model
{
    protected $fillable = [
        'module',
        'exam_type',
        'raw_score',
        'band_score',
    ];

    protected function casts(): array
    {
        return [
            'raw_score' => 'integer',
            'band_score' => 'decimal:1',
        ];
    }
}
