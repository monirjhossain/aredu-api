<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Video extends Model
{
    public const PENDING = 'pending';

    public const UPLOADING = 'uploading';

    public const PROCESSING = 'processing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    protected $fillable = [
        'lesson_id',
        'cloudflare_uid',
        'status',
        'duration_seconds',
        'error_message',
        'cover_path',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
