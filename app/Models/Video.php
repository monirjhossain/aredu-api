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

    public const PROVIDER_YOUTUBE = 'youtube';

    public const PROVIDER_VIMEO = 'vimeo';

    public const PROVIDER_CLOUDFLARE = 'cloudflare';

    protected $fillable = [
        'lesson_id',
        'provider',
        'external_id',
        'embed_url',
        'source_url',
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

    public function isEmbed(): bool
    {
        return in_array($this->provider, [self::PROVIDER_YOUTUBE, self::PROVIDER_VIMEO], true);
    }
}
