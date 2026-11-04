<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Lesson;
use App\Services\Lesson\LessonEngagementService;
use App\Services\Progress\LessonProgressService;
use App\Services\Video\VideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LessonPlaybackController extends Controller
{
    public function __construct(
        private readonly VideoService $videos,
        private readonly LessonProgressService $progress,
        private readonly LessonEngagementService $engagement,
    ) {}

    public function __invoke(Request $request, Lesson $lesson): JsonResponse
    {
        $progress = $this->progress->forLesson($request->user(), $lesson);
        $resumeAt = 0;
        if (empty($progress['completed_at'])) {
            $resumeAt = max(0, (int) ($progress['position_seconds'] ?? 0));
        }

        $payload = $this->videos->playbackFor($request->user(), $lesson, $resumeAt);
        $payload['progress'] = $progress;
        $payload['engagement'] = $this->engagement->summary($request->user(), $lesson);

        return ApiResponse::success($payload, 'Playback authorized.');
    }
}
