<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Writing\WritingSubmissionRequest;
use App\Http\Resources\WritingSubmissionResource;
use App\Http\Responses\ApiResponse;
use App\Models\WritingSubmission;
use App\Models\WritingTask;
use App\Services\Writing\WritingSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WritingSubmissionController extends Controller
{
    public function __construct(private readonly WritingSubmissionService $submissions) {}

    public function showForTask(Request $request, WritingTask $writingTask): JsonResponse
    {
        return ApiResponse::success(
            WritingSubmissionResource::make(
                $this->submissions->forStudentTask($request->user(), $writingTask)->load('task')
            )->resolve(),
            'Writing submission retrieved.',
        );
    }

    public function saveDraft(WritingSubmissionRequest $request, WritingTask $writingTask): JsonResponse
    {
        return ApiResponse::success(
            WritingSubmissionResource::make(
                $this->submissions->saveDraft($request->user(), $writingTask, $request->validated())
            )->resolve(),
            'Draft saved.',
        );
    }

    public function submit(WritingSubmissionRequest $request, WritingTask $writingTask): JsonResponse
    {
        return ApiResponse::success(
            WritingSubmissionResource::make(
                $this->submissions->submit($request->user(), $writingTask, $request->validated())
            )->resolve(),
            'Writing submitted.',
        );
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->submissions->paginateForStudent($request->user());

        return ApiResponse::success(
            WritingSubmissionResource::collection($page->getCollection())->resolve(),
            'Writing submissions retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    public function show(Request $request, WritingSubmission $submission): JsonResponse
    {
        return ApiResponse::success(
            WritingSubmissionResource::make(
                $this->submissions->showOwned($request->user(), $submission)
            )->resolve(),
            'Writing submission retrieved.',
        );
    }
}
