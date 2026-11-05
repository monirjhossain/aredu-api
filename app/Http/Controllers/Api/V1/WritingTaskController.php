<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MockTest\ReadingMockTestPublicationRequest;
use App\Http\Requests\Writing\WritingTaskImageRequest;
use App\Http\Requests\Writing\WritingTaskIndexRequest;
use App\Http\Requests\Writing\WritingTaskRequest;
use App\Http\Resources\WritingTaskResource;
use App\Http\Responses\ApiResponse;
use App\Models\WritingTask;
use App\Services\Practice\PracticeAccessService;
use App\Services\Writing\WritingTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WritingTaskController extends Controller
{
    public function __construct(
        private readonly WritingTaskService $tasks,
        private readonly PracticeAccessService $practiceAccess,
    ) {}

    public function index(WritingTaskIndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', WritingTask::class);
        $page = $this->tasks->paginate($request->validated());

        return ApiResponse::success(
            WritingTaskResource::collection($page->getCollection())->resolve(),
            'Writing tasks retrieved.',
            200,
            $this->meta($page),
        );
    }

    public function store(WritingTaskRequest $request): JsonResponse
    {
        $this->authorize('create', WritingTask::class);

        return ApiResponse::success(
            WritingTaskResource::make($this->tasks->create($request->validated()))->resolve(),
            'Writing task created.',
            201,
        );
    }

    public function show(WritingTask $writingTask): JsonResponse
    {
        $this->authorize('view', $writingTask);

        return ApiResponse::success(
            WritingTaskResource::make($this->tasks->find($writingTask))->resolve(),
            'Writing task retrieved.',
        );
    }

    public function update(WritingTaskRequest $request, WritingTask $writingTask): JsonResponse
    {
        $this->authorize('update', $writingTask);

        return ApiResponse::success(
            WritingTaskResource::make($this->tasks->update($writingTask, $request->validated()))->resolve(),
            'Writing task updated.',
        );
    }

    public function publication(ReadingMockTestPublicationRequest $request, WritingTask $writingTask): JsonResponse
    {
        $this->authorize('update', $writingTask);

        return ApiResponse::success(
            WritingTaskResource::make(
                $this->tasks->publish($writingTask, $request->boolean('is_published'))
            )->resolve(),
            'Writing task publication updated.',
        );
    }

    public function destroy(WritingTask $writingTask): JsonResponse
    {
        $this->authorize('delete', $writingTask);
        $this->tasks->delete($writingTask);

        return ApiResponse::success(null, 'Writing task deleted.');
    }

    public function storeImage(WritingTaskImageRequest $request, WritingTask $writingTask): JsonResponse
    {
        $this->authorize('update', $writingTask);

        return ApiResponse::success(
            WritingTaskResource::make(
                $this->tasks->storeImage($writingTask, $request->file('image'))
            )->resolve(),
            'Writing task image uploaded.',
        );
    }

    public function destroyImage(WritingTask $writingTask): JsonResponse
    {
        $this->authorize('update', $writingTask);

        return ApiResponse::success(
            WritingTaskResource::make($this->tasks->destroyImage($writingTask))->resolve(),
            'Writing task image removed.',
        );
    }

    public function studentIndex(WritingTaskIndexRequest $request): JsonResponse
    {
        $page = $this->tasks->paginateForStudent($request->user(), $request->validated());

        return ApiResponse::success(
            WritingTaskResource::collection($page->getCollection())->resolve(),
            'Published writing tasks retrieved.',
            200,
            $this->meta($page, $request->user()),
        );
    }

    public function studentShow(Request $request, WritingTask $writingTask): JsonResponse
    {
        $task = $this->tasks->findPublishedForStudent($request->user(), $writingTask->id);

        return ApiResponse::success(
            WritingTaskResource::make($task)->resolve(),
            'Writing task retrieved.',
        );
    }

    /**
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, WritingTask>  $page
     * @return array<string, mixed>
     */
    private function meta($page, $user = null): array
    {
        $meta = [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];

        if ($user !== null) {
            $meta['practice_access'] = $this->practiceAccess->meta($user);
        }

        return $meta;
    }
}
