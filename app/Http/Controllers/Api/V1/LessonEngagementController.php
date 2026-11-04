<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lesson\StoreLessonFeedbackRequest;
use App\Http\Requests\Lesson\StoreLessonReactionRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Lesson;
use App\Services\Lesson\LessonEngagementService;
use Illuminate\Http\JsonResponse;

class LessonEngagementController extends Controller
{
    public function __construct(private readonly LessonEngagementService $engagement) {}

    public function react(StoreLessonReactionRequest $request, Lesson $lesson): JsonResponse
    {
        $summary = $this->engagement->react(
            $request->user(),
            $lesson,
            $request->validated('type'),
        );

        return ApiResponse::success($summary, 'Reaction saved.');
    }

    public function feedback(StoreLessonFeedbackRequest $request, Lesson $lesson): JsonResponse
    {
        $payload = $this->engagement->submitFeedback(
            $request->user(),
            $lesson,
            $request->validated('body'),
        );

        return ApiResponse::success($payload, 'Feedback submitted.');
    }
}
