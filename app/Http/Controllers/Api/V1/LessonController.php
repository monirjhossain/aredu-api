<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\LessonOrderRequest;
use App\Http\Requests\Catalog\LessonRequest;
use App\Http\Resources\CourseSectionResource;
use App\Http\Resources\LessonResource;
use App\Http\Responses\ApiResponse;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Services\Catalog\CurriculumService;
use Illuminate\Http\JsonResponse;

class LessonController extends Controller
{
    public function __construct(private readonly CurriculumService $curriculum) {}

    public function store(LessonRequest $request, Course $course, CourseSection $section): JsonResponse
    {
        $this->authorize('manageContent', $course);
        $section = $this->curriculum->sectionFor($course, $section);

        $lesson = $this->curriculum->createLesson($section, $request->validated());

        return ApiResponse::success(
            LessonResource::make($lesson)->resolve(),
            'Lesson created.',
            201,
        );
    }

    public function update(LessonRequest $request, Course $course, CourseSection $section, Lesson $lesson): JsonResponse
    {
        $this->authorize('manageContent', $course);
        $section = $this->curriculum->sectionFor($course, $section);
        $lesson = $this->curriculum->lessonFor($section, $lesson);

        $updated = $this->curriculum->updateLesson($lesson, $request->validated());

        return ApiResponse::success(
            LessonResource::make($updated)->resolve(),
            'Lesson updated.',
        );
    }

    public function destroy(Course $course, CourseSection $section, Lesson $lesson): JsonResponse
    {
        $this->authorize('manageContent', $course);
        $section = $this->curriculum->sectionFor($course, $section);
        $lesson = $this->curriculum->lessonFor($section, $lesson);
        $this->curriculum->deleteLesson($section, $lesson);

        return ApiResponse::success(null, 'Lesson deleted.');
    }

    public function reorder(LessonOrderRequest $request, Course $course, CourseSection $section): JsonResponse
    {
        $this->authorize('manageContent', $course);
        $section = $this->curriculum->sectionFor($course, $section);
        $this->curriculum->reorderLessons($section, $request->validated('lesson_ids'));

        $tree = $this->curriculum->tree($course);

        return ApiResponse::success([
            'course' => $tree['course'],
            'sections' => CourseSectionResource::collection($tree['sections'])->resolve(),
        ], 'Lessons reordered.');
    }
}
