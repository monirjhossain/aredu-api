<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CourseIndexRequest;
use App\Http\Requests\Catalog\CoursePublicationRequest;
use App\Http\Requests\Catalog\CourseRequest;
use App\Http\Requests\Catalog\CourseTeachersRequest;
use App\Http\Requests\Catalog\CourseThumbnailRequest;
use App\Http\Resources\CourseResource;
use App\Http\Responses\ApiResponse;
use App\Models\Course;
use App\Services\Catalog\CourseService;
use Illuminate\Http\JsonResponse;

class CourseController extends Controller
{
    public function __construct(private readonly CourseService $courses) {}

    public function index(CourseIndexRequest $request): JsonResponse
    {
        $page = $this->courses->paginate($request->validated());

        return ApiResponse::success(
            CourseResource::collection($page->getCollection())->resolve(),
            'Courses retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    public function formOptions(): JsonResponse
    {
        $this->authorize('viewAny', Course::class);

        return ApiResponse::success($this->courses->formOptions(), 'Course form options retrieved.');
    }

    public function store(CourseRequest $request): JsonResponse
    {
        $this->authorize('create', Course::class);

        return ApiResponse::success(
            CourseResource::make($this->courses->create($request->validated()))->resolve(),
            'Course created.',
            201,
        );
    }

    public function show(Course $course): JsonResponse
    {
        $this->authorize('view', $course);

        return ApiResponse::success(
            CourseResource::make($this->courses->find($course))->resolve(),
            'Course retrieved.',
        );
    }

    public function update(CourseRequest $request, Course $course): JsonResponse
    {
        $this->authorize('update', $course);

        return ApiResponse::success(
            CourseResource::make($this->courses->update($course, $request->validated()))->resolve(),
            'Course updated.',
        );
    }

    public function publication(CoursePublicationRequest $request, Course $course): JsonResponse
    {
        $this->authorize('update', $course);

        $updated = $this->courses->publish($course, $request->boolean('is_published'));

        return ApiResponse::success(
            CourseResource::make($updated)->resolve(),
            'Course publication updated.',
        );
    }

    public function syncTeachers(CourseTeachersRequest $request, Course $course): JsonResponse
    {
        $this->authorize('update', $course);

        $updated = $this->courses->syncTeachers($course, $request->validated('teachers'));

        return ApiResponse::success(
            CourseResource::make($updated)->resolve(),
            'Course teachers updated.',
        );
    }

    public function thumbnail(CourseThumbnailRequest $request, Course $course): JsonResponse
    {
        $this->authorize('update', $course);

        $updated = $this->courses->storeThumbnail($course, $request->file('thumbnail'));

        return ApiResponse::success(
            CourseResource::make($updated)->resolve(),
            'Course thumbnail updated.',
        );
    }

    public function destroyThumbnail(Course $course): JsonResponse
    {
        $this->authorize('update', $course);

        return ApiResponse::success(
            CourseResource::make($this->courses->deleteThumbnail($course))->resolve(),
            'Course thumbnail removed.',
        );
    }

    public function destroy(Course $course): JsonResponse
    {
        $this->authorize('delete', $course);
        $this->courses->delete($course);

        return ApiResponse::success(null, 'Course deleted.');
    }
}
