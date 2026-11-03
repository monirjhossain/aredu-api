<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Progress\UpdateLessonProgressRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Lesson;
use App\Services\Progress\LessonProgressService;
use Illuminate\Http\JsonResponse;

class LessonProgressController extends Controller
{
    public function __construct(private readonly LessonProgressService $progress) {}

    public function update(UpdateLessonProgressRequest $request, Lesson $lesson): JsonResponse
    {
        $payload = $this->progress->updatePosition(
            $request->user(),
            $lesson,
            (int) $request->validated('position_seconds'),
        );

        return ApiResponse::success($payload, $payload['saved'] ? 'Progress saved.' : 'Progress unchanged.');
    }

    public function complete(Lesson $lesson): JsonResponse
    {
        $payload = $this->progress->markCompleted(request()->user(), $lesson);

        return ApiResponse::success($payload, 'Lesson marked as completed.');
    }
}
