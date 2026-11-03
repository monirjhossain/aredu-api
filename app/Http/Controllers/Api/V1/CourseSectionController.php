<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SectionOrderRequest;
use App\Http\Requests\Catalog\SectionRequest;
use App\Http\Resources\CourseSectionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Course;
use App\Models\CourseSection;
use App\Services\Catalog\CurriculumService;
use Illuminate\Http\JsonResponse;

class CourseSectionController extends Controller
{
    public function __construct(private readonly CurriculumService $curriculum) {}

    public function index(Course $course): JsonResponse
    {
        $this->authorize('manageContent', $course);

        return ApiResponse::success($this->payload($course), 'Sections retrieved.');
    }

    public function store(SectionRequest $request, Course $course): JsonResponse
    {
        $this->authorize('manageContent', $course);

        $section = $this->curriculum->createSection($course, $request->validated('title'));

        return ApiResponse::success(
            CourseSectionResource::make($section)->resolve(),
            'Section created.',
            201,
        );
    }

    public function update(SectionRequest $request, Course $course, CourseSection $section): JsonResponse
    {
        $this->authorize('manageContent', $course);
        $section = $this->curriculum->sectionFor($course, $section);

        $updated = $this->curriculum->updateSection($section, $request->validated('title'));

        return ApiResponse::success(
            CourseSectionResource::make($updated)->resolve(),
            'Section updated.',
        );
    }

    public function destroy(Course $course, CourseSection $section): JsonResponse
    {
        $this->authorize('manageContent', $course);
        $section = $this->curriculum->sectionFor($course, $section);
        $this->curriculum->deleteSection($course, $section);

        return ApiResponse::success(null, 'Section deleted.');
    }

    public function reorder(SectionOrderRequest $request, Course $course): JsonResponse
    {
        $this->authorize('manageContent', $course);
        $this->curriculum->reorderSections($course, $request->validated('section_ids'));

        return ApiResponse::success($this->payload($course), 'Sections reordered.');
    }

    /**
     * @return array{course: array{id: int, title: string}, sections: mixed}
     */
    private function payload(Course $course): array
    {
        $tree = $this->curriculum->tree($course);

        return [
            'course' => $tree['course'],
            'sections' => CourseSectionResource::collection($tree['sections'])->resolve(),
        ];
    }
}
