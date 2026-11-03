<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\PublicCourseIndexRequest;
use App\Http\Resources\PublicCourseResource;
use App\Http\Responses\ApiResponse;
use App\Services\Catalog\PublicCourseService;
use Illuminate\Http\JsonResponse;

class PublicCourseController extends Controller
{
    public function __construct(private readonly PublicCourseService $courses) {}

    public function index(PublicCourseIndexRequest $request): JsonResponse
    {
        $page = $this->courses->paginate($request->validated(), $this->courses->viewer());

        return ApiResponse::success(
            PublicCourseResource::collection($page->getCollection())->resolve(),
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

    public function categories(): JsonResponse
    {
        $categories = $this->courses->categories()->map(fn ($category) => [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
        ])->values();

        return ApiResponse::success($categories, 'Categories retrieved.');
    }

    public function show(string $slug): JsonResponse
    {
        $course = $this->courses->findPublished($slug, $this->courses->viewer());

        return ApiResponse::success(
            PublicCourseResource::make($course)->resolve(),
            'Course retrieved.',
        );
    }
}
