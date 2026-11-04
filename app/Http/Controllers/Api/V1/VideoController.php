<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Video\VideoCoverRequest;
use App\Http\Requests\Video\VideoEmbedRequest;
use App\Http\Resources\VideoResource;
use App\Http\Responses\ApiResponse;
use App\Models\Lesson;
use App\Models\Video;
use App\Services\Video\VideoService;
use Illuminate\Http\JsonResponse;

class VideoController extends Controller
{
    public function __construct(private readonly VideoService $videos) {}

    public function show(Lesson $lesson): JsonResponse
    {
        $this->authorizeLessonVideo($lesson);

        $video = $this->videos->findForLesson($lesson);

        if ($video === null) {
            return ApiResponse::success(null, 'No video for this lesson.');
        }

        return ApiResponse::success(
            VideoResource::make($video)->resolve(),
            'Video retrieved.',
        );
    }

    public function store(VideoEmbedRequest $request, Lesson $lesson): JsonResponse
    {
        $this->authorizeLessonVideo($lesson);

        $video = $this->videos->upsertEmbed(
            $lesson,
            (string) $request->validated('url'),
            $request->validated('duration_seconds'),
        );

        return ApiResponse::success(
            VideoResource::make($video)->resolve(),
            'Video saved.',
            201,
        );
    }

    public function storeCover(VideoCoverRequest $request, Lesson $lesson): JsonResponse
    {
        $this->authorizeLessonVideo($lesson);

        $video = $this->videos->storeCover($lesson, $request->file('cover'));

        return ApiResponse::success(
            VideoResource::make($video)->resolve(),
            'Video cover updated.',
        );
    }

    public function destroyCover(Lesson $lesson): JsonResponse
    {
        $this->authorizeLessonVideo($lesson);

        $video = $this->videos->deleteCover($lesson);

        return ApiResponse::success(
            VideoResource::make($video)->resolve(),
            'Video cover removed.',
        );
    }

    private function authorizeLessonVideo(Lesson $lesson): void
    {
        $this->authorize('create', Video::class);
        $lesson->loadMissing('section.course');
        $this->authorize('manageContent', $lesson->section->course);
    }
}
