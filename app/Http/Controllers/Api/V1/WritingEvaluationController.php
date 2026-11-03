<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Writing\WritingEvaluationRequest;
use App\Http\Requests\Writing\WritingTeacherSubmissionIndexRequest;
use App\Http\Resources\WritingEvaluationResource;
use App\Http\Resources\WritingSubmissionResource;
use App\Http\Responses\ApiResponse;
use App\Models\WritingEvaluation;
use App\Models\WritingSubmission;
use App\Services\Writing\WritingEvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WritingEvaluationController extends Controller
{
    public function __construct(private readonly WritingEvaluationService $evaluations) {}

    public function index(WritingTeacherSubmissionIndexRequest $request): JsonResponse
    {
        $page = $this->evaluations->paginateForTeacher($request->validated());

        return ApiResponse::success(
            WritingSubmissionResource::collection($page->getCollection())->resolve(),
            'Writing submissions for evaluation retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    public function show(WritingSubmission $submission): JsonResponse
    {
        return ApiResponse::success(
            WritingSubmissionResource::make(
                $this->evaluations->showForTeacher($submission)
            )->resolve(),
            'Writing submission retrieved for evaluation.',
        );
    }

    public function store(WritingEvaluationRequest $request, WritingSubmission $submission): JsonResponse
    {
        return ApiResponse::success(
            WritingEvaluationResource::make(
                $this->evaluations->evaluate($request->user(), $submission, $request->validated())
            )->resolve(),
            'Writing evaluation returned.',
            201,
        );
    }

    public function update(WritingEvaluationRequest $request, WritingEvaluation $evaluation): JsonResponse
    {
        return ApiResponse::success(
            WritingEvaluationResource::make(
                $this->evaluations->update($request->user(), $evaluation, $request->validated())
            )->resolve(),
            'Writing evaluation updated.',
        );
    }

    public function studentShow(Request $request, WritingSubmission $submission): JsonResponse
    {
        return ApiResponse::success(
            WritingSubmissionResource::make(
                $this->evaluations->showForStudent($request->user(), $submission)
            )->resolve(),
            'Writing submission evaluation retrieved.',
        );
    }
}
