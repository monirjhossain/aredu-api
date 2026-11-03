<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CategoryRequest;
use App\Http\Resources\CourseCategoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\CourseCategory;
use App\Services\Catalog\CourseCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseCategoryController extends Controller
{
    public function __construct(private readonly CourseCategoryService $categories) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CourseCategory::class);

        $search = $request->string('search')->trim()->toString();
        $page = $this->categories->paginate($search === '' ? null : $search);

        return ApiResponse::success(
            CourseCategoryResource::collection($page->getCollection())->resolve(),
            'Categories retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $this->authorize('create', CourseCategory::class);

        return ApiResponse::success(
            CourseCategoryResource::make($this->categories->create($request->validated()))->resolve(),
            'Category created.',
            201,
        );
    }

    public function show(CourseCategory $category): JsonResponse
    {
        $this->authorize('view', $category);

        return ApiResponse::success(
            CourseCategoryResource::make($this->categories->find($category))->resolve(),
            'Category retrieved.',
        );
    }

    public function update(CategoryRequest $request, CourseCategory $category): JsonResponse
    {
        $this->authorize('update', $category);

        return ApiResponse::success(
            CourseCategoryResource::make($this->categories->update($category, $request->validated()))->resolve(),
            'Category updated.',
        );
    }

    public function destroy(CourseCategory $category): JsonResponse
    {
        $this->authorize('delete', $category);
        $this->categories->delete($category);

        return ApiResponse::success(null, 'Category deleted.');
    }
}
