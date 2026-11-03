<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Speaking\SpeakingEvaluationRequest;
use App\Http\Requests\Speaking\SpeakingTeacherRecordingIndexRequest;
use App\Http\Resources\SpeakingEvaluationResource;
use App\Http\Resources\SpeakingRecordingResource;
use App\Http\Responses\ApiResponse;
use App\Models\SpeakingEvaluation;
use App\Models\SpeakingRecording;
use App\Services\Speaking\SpeakingEvaluationService;
use Illuminate\Http\JsonResponse;

class SpeakingEvaluationController extends Controller
{
    public function __construct(private readonly SpeakingEvaluationService $evaluations) {}

    public function index(SpeakingTeacherRecordingIndexRequest $request): JsonResponse
    {
        $page = $this->evaluations->paginateForTeacher($request->validated());

        return ApiResponse::success(
            SpeakingRecordingResource::collection($page->getCollection())->resolve(),
            'Speaking recordings for evaluation retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    public function show(SpeakingRecording $recording): JsonResponse
    {
        return ApiResponse::success(
            SpeakingRecordingResource::make(
                $this->evaluations->showForTeacher($recording)
            )->resolve(),
            'Speaking recording retrieved for evaluation.',
        );
    }

    public function store(SpeakingEvaluationRequest $request, SpeakingRecording $recording): JsonResponse
    {
        return ApiResponse::success(
            SpeakingEvaluationResource::make(
                $this->evaluations->evaluate($request->user(), $recording, $request->validated())
            )->resolve(),
            'Speaking evaluation returned.',
            201,
        );
    }

    public function update(SpeakingEvaluationRequest $request, SpeakingEvaluation $evaluation): JsonResponse
    {
        return ApiResponse::success(
            SpeakingEvaluationResource::make(
                $this->evaluations->update($request->user(), $evaluation, $request->validated())
            )->resolve(),
            'Speaking evaluation updated.',
        );
    }
}
