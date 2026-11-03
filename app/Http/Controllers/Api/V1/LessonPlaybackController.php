<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Lesson;
use App\Services\Progress\LessonProgressService;
use App\Services\Video\VideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LessonPlaybackController extends Controller
{
    public function __construct(
        private readonly VideoService $videos,
        private readonly LessonProgressService $progress,
    ) {}

    public function __invoke(Request $request, Lesson $lesson): JsonResponse
    {
        $payload = $this->videos->playbackFor($request->user(), $lesson);
        $progress = $this->progress->forLesson($request->user(), $lesson);
        $payload['progress'] = $progress;

        $resumeAt = (int) ($progress['position_seconds'] ?? 0);
        if ($resumeAt > 0 && empty($progress['completed_at'])) {
            $separator = str_contains($payload['playback']['iframe_url'], '?') ? '&' : '?';
            $payload['playback']['iframe_url'] .= $separator.'startTime='.$resumeAt;
        }

        return ApiResponse::success($payload, 'Playback authorized.');
    }
}
