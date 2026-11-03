<?php

namespace App\Services\Video;

use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\User;
use App\Models\Video;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VideoService
{
    public function __construct(private readonly CloudflareStreamClient $cloudflare) {}

    /**
     * @return array{
     *     lesson_id: int,
     *     duration_seconds: int|null,
     *     expires_in: int,
     *     playback: array{token: string, iframe_url: string, hls_url: string}
     * }
     */
    public function playbackFor(User $user, Lesson $lesson): array
    {
        $lesson->loadMissing(['section.course', 'video']);

        if (! $lesson->is_published || $lesson->section === null || $lesson->section->course === null) {
            throw new AuthorizationException('This lesson is not available for playback.');
        }

        $courseId = (int) $lesson->section->course_id;
        $isFreePreview = $lesson->is_free_preview;

        if (! $isFreePreview && ! $this->hasActiveEnrollment($user->id, $courseId)) {
            throw new AuthorizationException('An active enrollment is required to watch this lesson.');
        }

        $video = $lesson->video;

        if ($video === null || $video->status !== Video::READY || ! is_string($video->cloudflare_uid) || $video->cloudflare_uid === '') {
            throw new HttpException(404, 'This lesson has no ready video.');
        }

        $expiresIn = $this->playbackTtlSeconds();
        $token = $this->cloudflare->createPlaybackToken($video->cloudflare_uid, $expiresIn)['token'];
        $base = $this->customerBaseUrl();
        $iframeUrl = $base.'/'.rawurlencode($token).'/iframe';
        $coverUrl = $this->coverUrl($video);

        if ($coverUrl !== null) {
            $iframeUrl .= '?poster='.rawurlencode($coverUrl);
        }

        return [
            'lesson_id' => (int) $lesson->id,
            'duration_seconds' => $video->duration_seconds,
            'cover_url' => $coverUrl,
            'expires_in' => $expiresIn,
            'playback' => [
                'token' => $token,
                'iframe_url' => $iframeUrl,
                'hls_url' => $base.'/'.rawurlencode($token).'/manifest/video.m3u8',
            ],
        ];
    }

    public function storeCover(Lesson $lesson, UploadedFile $file): Video
    {
        $video = $this->findForLesson($lesson);

        if ($video === null) {
            throw ValidationException::withMessages([
                'cover' => ['Upload a video before setting a cover.'],
            ]);
        }

        $path = $file->store('video-covers', 'public');
        $previous = $video->cover_path;
        $video->cover_path = $path;
        $video->save();

        if (is_string($previous) && $previous !== '') {
            Storage::disk('public')->delete($previous);
        }

        return $video->fresh();
    }

    public function deleteCover(Lesson $lesson): Video
    {
        $video = $this->findForLesson($lesson);

        if ($video === null) {
            throw ValidationException::withMessages([
                'cover' => ['This lesson has no video.'],
            ]);
        }

        if (is_string($video->cover_path) && $video->cover_path !== '') {
            Storage::disk('public')->delete($video->cover_path);
        }

        $video->cover_path = null;
        $video->save();

        return $video;
    }

    public function coverUrl(?Video $video): ?string
    {
        if ($video === null || ! is_string($video->cover_path) || $video->cover_path === '') {
            return null;
        }

        return url('storage/'.$video->cover_path);
    }

    /**
     * @return array{video: Video, upload_url: string}
     */
    public function createDirectUpload(Lesson $lesson): array
    {
        $existing = Video::query()->where('lesson_id', $lesson->id)->first();

        if ($existing !== null && $existing->status !== Video::FAILED) {
            throw ValidationException::withMessages([
                'lesson_id' => ['This lesson already has a video.'],
            ]);
        }

        $created = $this->cloudflare->createDirectUpload($this->maxDurationSeconds());

        $video = DB::transaction(function () use ($lesson, $created) {
            $current = Video::query()->where('lesson_id', $lesson->id)->lockForUpdate()->first();

            if ($current !== null && $current->status !== Video::FAILED) {
                throw ValidationException::withMessages([
                    'lesson_id' => ['This lesson already has a video.'],
                ]);
            }

            $attributes = [
                'cloudflare_uid' => $created['uid'],
                'status' => Video::UPLOADING,
                'duration_seconds' => null,
                'error_message' => null,
            ];

            try {
                if ($current === null) {
                    return Video::query()->create([
                        'lesson_id' => $lesson->id,
                        ...$attributes,
                    ]);
                }

                $current->fill($attributes);
                $current->save();

                return $current;
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'lesson_id' => ['This lesson already has a video.'],
                ]);
            }
        });

        return [
            'video' => $video,
            'upload_url' => $created['upload_url'],
        ];
    }

    public function findForLesson(Lesson $lesson): ?Video
    {
        return Video::query()->where('lesson_id', $lesson->id)->first();
    }

    /**
     * Apply a verified Cloudflare Stream webhook payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function applyCloudflareNotification(array $payload): ?Video
    {
        $uid = $payload['uid'] ?? null;

        if (! is_string($uid) || $uid === '') {
            throw ValidationException::withMessages([
                'uid' => ['The video uid is required.'],
            ]);
        }

        $video = Video::query()->where('cloudflare_uid', $uid)->first();

        if ($video === null) {
            return null;
        }

        $state = data_get($payload, 'status.state');

        if ($state === 'ready') {
            return $this->applyReady($video, $payload);
        }

        if ($state === 'error') {
            return $this->applyFailed($video, $payload);
        }

        return $video;
    }

    public function markProcessing(Video $video, ?int $durationSeconds = null): Video
    {
        if ($video->status === Video::PROCESSING) {
            if ($durationSeconds !== null) {
                $video->duration_seconds = $this->duration($durationSeconds);
                $video->save();
            }

            return $video;
        }

        $this->assertTransition($video, [Video::PENDING, Video::UPLOADING], Video::PROCESSING);
        $video->status = Video::PROCESSING;
        $video->error_message = null;

        if ($durationSeconds !== null) {
            $video->duration_seconds = $this->duration($durationSeconds);
        }

        $video->save();

        return $video;
    }

    public function markReady(Video $video, int $durationSeconds): Video
    {
        if ($video->status === Video::READY) {
            $video->duration_seconds = $this->duration($durationSeconds);
            $video->error_message = null;
            $video->save();

            return $video;
        }

        $this->assertTransition($video, [Video::PENDING, Video::UPLOADING, Video::PROCESSING], Video::READY);
        $video->status = Video::READY;
        $video->duration_seconds = $this->duration($durationSeconds);
        $video->error_message = null;
        $video->save();

        return $video;
    }

    public function markFailed(Video $video, string $errorMessage): Video
    {
        $message = trim($errorMessage);

        if ($message === '') {
            throw ValidationException::withMessages([
                'error_message' => ['A failed video needs an error message.'],
            ]);
        }

        if ($video->status === Video::FAILED) {
            $video->error_message = mb_substr($message, 0, 1000);
            $video->save();

            return $video;
        }

        if ($video->status === Video::READY) {
            return $video;
        }

        $this->assertTransition($video, [Video::PENDING, Video::UPLOADING, Video::PROCESSING], Video::FAILED);
        $video->status = Video::FAILED;
        $video->error_message = mb_substr($message, 0, 1000);
        $video->save();

        return $video;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyReady(Video $video, array $payload): Video
    {
        $duration = $payload['duration'] ?? null;
        $seconds = is_numeric($duration) ? (int) round((float) $duration) : (int) ($video->duration_seconds ?? 0);

        return $this->markReady($video, $seconds);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyFailed(Video $video, array $payload): Video
    {
        $message = data_get($payload, 'status.errorReasonText')
            ?: data_get($payload, 'status.errReasonText')
            ?: data_get($payload, 'status.errorReasonCode')
            ?: data_get($payload, 'status.errReasonCode')
            ?: 'Video processing failed.';

        return $this->markFailed($video, is_string($message) ? $message : 'Video processing failed.');
    }

    private function hasActiveEnrollment(int $userId, int $courseId): bool
    {
        return Enrollment::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('status', 'active')
            ->exists();
    }

    private function playbackTtlSeconds(): int
    {
        $seconds = (int) config('services.cloudflare.playback_ttl_seconds', 3600);

        return $seconds >= 60 ? $seconds : 3600;
    }

    private function customerBaseUrl(): string
    {
        $code = config('services.cloudflare.customer_code');

        if (! is_string($code) || $code === '') {
            throw new HttpException(503, 'Video playback is not configured.');
        }

        $code = preg_replace('#^https?://#', '', trim($code)) ?? '';
        $code = rtrim($code, '/');

        if ($code === '') {
            throw new HttpException(503, 'Video playback is not configured.');
        }

        if (! str_contains($code, '.')) {
            $code = 'customer-'.$code.'.cloudflarestream.com';
        }

        return 'https://'.$code;
    }

    private function maxDurationSeconds(): int
    {
        $seconds = (int) config('services.cloudflare.max_duration_seconds', 3600);

        return $seconds > 0 ? $seconds : 3600;
    }

    private function duration(int $seconds): int
    {
        if ($seconds < 0 || $seconds > 4294967295) {
            throw ValidationException::withMessages([
                'duration_seconds' => ['The video duration is invalid.'],
            ]);
        }

        return $seconds;
    }

    /**
     * @param  list<string>  $from
     */
    private function assertTransition(Video $video, array $from, string $to): void
    {
        if (! in_array($video->status, $from, true)) {
            throw ValidationException::withMessages([
                'status' => ['This video cannot move from '.$video->status.' to '.$to.'.'],
            ]);
        }
    }
}
