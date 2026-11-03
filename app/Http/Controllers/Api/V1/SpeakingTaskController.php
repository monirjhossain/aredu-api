<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MockTest\ReadingMockTestPublicationRequest;
use App\Http\Requests\Speaking\SpeakingQuestionRequest;
use App\Http\Requests\Speaking\SpeakingTaskIndexRequest;
use App\Http\Requests\Speaking\SpeakingTaskRequest;
use App\Http\Resources\SpeakingQuestionResource;
use App\Http\Resources\SpeakingTaskResource;
use App\Http\Responses\ApiResponse;
use App\Models\SpeakingQuestion;
use App\Models\SpeakingTask;
use App\Services\Speaking\SpeakingTaskService;
use Illuminate\Http\JsonResponse;

class SpeakingTaskController extends Controller
{
    public function __construct(private readonly SpeakingTaskService $tasks) {}

    public function index(SpeakingTaskIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', SpeakingTask::class);
        $page = $this->tasks->paginate($request->validated());

        return ApiResponse::success(
            SpeakingTaskResource::collection($page->getCollection())->resolve(),
            'Speaking tasks retrieved.',
            200,
            $this->meta($page),
        );
    }

    public function store(SpeakingTaskRequest $request): JsonResponse
    {
        $this->authorize('create', SpeakingTask::class);

        return ApiResponse::success(
            SpeakingTaskResource::make($this->tasks->create($request->validated()))->resolve(),
            'Speaking task created.',
            201,
        );
    }

    public function show(SpeakingTask $speakingTask): JsonResponse
    {
        $this->authorize('view', $speakingTask);

        return ApiResponse::success(
            SpeakingTaskResource::make($this->tasks->find($speakingTask))->resolve(),
            'Speaking task retrieved.',
        );
    }

    public function update(SpeakingTaskRequest $request, SpeakingTask $speakingTask): JsonResponse
    {
        $this->authorize('update', $speakingTask);

        return ApiResponse::success(
            SpeakingTaskResource::make($this->tasks->update($speakingTask, $request->validated()))->resolve(),
            'Speaking task updated.',
        );
    }

    public function publication(ReadingMockTestPublicationRequest $request, SpeakingTask $speakingTask): JsonResponse
    {
        $this->authorize('update', $speakingTask);

        return ApiResponse::success(
            SpeakingTaskResource::make(
                $this->tasks->publish($speakingTask, $request->boolean('is_published'))
            )->resolve(),
            'Speaking task publication updated.',
        );
    }

    public function destroy(SpeakingTask $speakingTask): JsonResponse
    {
        $this->authorize('delete', $speakingTask);
        $this->tasks->delete($speakingTask);

        return ApiResponse::success(null, 'Speaking task deleted.');
    }

    public function storeQuestion(SpeakingQuestionRequest $request, SpeakingTask $speakingTask): JsonResponse
    {
        $this->authorize('update', $speakingTask);

        return ApiResponse::success(
            SpeakingQuestionResource::make(
                $this->tasks->storeQuestion($speakingTask, $request->validated())
            )->resolve(),
            'Speaking question created.',
            201,
        );
    }

    public function updateQuestion(
        SpeakingQuestionRequest $request,
        SpeakingTask $speakingTask,
        SpeakingQuestion $question,
    ): JsonResponse {
        $this->authorize('update', $speakingTask);

        return ApiResponse::success(
            SpeakingQuestionResource::make(
                $this->tasks->updateQuestion($speakingTask, $question, $request->validated())
            )->resolve(),
            'Speaking question updated.',
        );
    }

    public function destroyQuestion(SpeakingTask $speakingTask, SpeakingQuestion $question): JsonResponse
    {
        $this->authorize('update', $speakingTask);
        $this->tasks->destroyQuestion($speakingTask, $question);

        return ApiResponse::success(null, 'Speaking question deleted.');
    }

    public function studentIndex(SpeakingTaskIndexRequest $request): JsonResponse
    {
        $page = $this->tasks->paginatePublished($request->validated());

        return ApiResponse::success(
            SpeakingTaskResource::collection($page->getCollection())->resolve(),
            'Published speaking tasks retrieved.',
            200,
            $this->meta($page),
        );
    }

    public function studentShow(SpeakingTask $speakingTask): JsonResponse
    {
        $task = $this->tasks->findPublished($speakingTask->id);

        return ApiResponse::success(
            SpeakingTaskResource::make($task)->resolve(),
            'Speaking task retrieved.',
        );
    }

    /**
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, SpeakingTask>  $page
     * @return array<string, int>
     */
    private function meta($page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
    }
}
