<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Speaking\SpeakingRecordingUploadRequest;
use App\Http\Resources\SpeakingRecordingResource;
use App\Http\Responses\ApiResponse;
use App\Models\SpeakingRecording;
use App\Models\SpeakingTask;
use App\Services\Speaking\SpeakingRecordingService;
use App\Support\ExamContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpeakingRecordingController extends Controller
{
    public function __construct(private readonly SpeakingRecordingService $recordings) {}

    public function showForTask(Request $request, SpeakingTask $speakingTask): JsonResponse
    {
        $contextKey = ExamContext::normalize($request->query('context_key'));
        $recording = $this->recordings->forStudentTask($request->user(), $speakingTask, $contextKey);

        return ApiResponse::success(
            $recording ? SpeakingRecordingResource::make($recording)->resolve() : null,
            'Speaking recording retrieved.',
        );
    }

    public function upload(SpeakingRecordingUploadRequest $request, SpeakingTask $speakingTask): JsonResponse
    {
        $data = $request->validated();
        $contextKey = ExamContext::normalize($data['context_key'] ?? $request->query('context_key'));

        return ApiResponse::success(
            SpeakingRecordingResource::make(
                $this->recordings->upload(
                    $request->user(),
                    $speakingTask,
                    $request->file('recording'),
                    isset($data['duration_seconds']) ? (int) $data['duration_seconds'] : null,
                    $contextKey,
                )
            )->resolve(),
            'Speaking recording uploaded.',
            201,
        );
    }

    public function submit(Request $request, SpeakingTask $speakingTask): JsonResponse
    {
        $contextKey = ExamContext::normalize($request->input('context_key', $request->query('context_key')));

        return ApiResponse::success(
            SpeakingRecordingResource::make(
                $this->recordings->submit($request->user(), $speakingTask, $contextKey)
            )->resolve(),
            'Speaking recording submitted.',
        );
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->recordings->paginateForStudent($request->user());

        return ApiResponse::success(
            SpeakingRecordingResource::collection($page->getCollection())->resolve(),
            'Speaking recordings retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    public function show(Request $request, SpeakingRecording $recording): JsonResponse
    {
        return ApiResponse::success(
            SpeakingRecordingResource::make(
                $this->recordings->showOwned($request->user(), $recording)
            )->resolve(),
            'Speaking recording retrieved.',
        );
    }

    public function stream(Request $request, SpeakingRecording $recording): StreamedResponse
    {
        return $this->recordings->stream($request->user(), $recording);
    }
}
