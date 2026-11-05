<?php

namespace App\Services\Writing;

use App\Models\User;
use App\Models\WritingTask;
use App\Services\Practice\PracticeAccessService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class WritingTaskService
{
    public function __construct(private readonly PracticeAccessService $practiceAccess) {}

    /**
     * @param  array{search?: string|null, exam_type?: string|null, task_type?: string|null, is_published?: string|null}  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $search = $this->term($filters['search'] ?? null);

        return WritingTask::query()
            ->when($search !== null, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', $search)->orWhere('prompt', 'like', $search);
                });
            })
            ->when(($filters['exam_type'] ?? null) !== null && $filters['exam_type'] !== '', function ($query) use ($filters) {
                $query->where('exam_type', $filters['exam_type']);
            })
            ->when(($filters['task_type'] ?? null) !== null && $filters['task_type'] !== '', function ($query) use ($filters) {
                $query->where('task_type', $filters['task_type']);
            })
            ->when(array_key_exists('is_published', $filters) && $filters['is_published'] !== null && $filters['is_published'] !== '', function ($query) use ($filters) {
                $query->where('is_published', $filters['is_published'] === '1' || $filters['is_published'] === 1 || $filters['is_published'] === true);
            })
            ->orderByDesc('id')
            ->paginate(15);
    }

    /**
     * @param  array{search?: string|null, exam_type?: string|null, task_type?: string|null}  $filters
     */
    public function paginatePublished(array $filters = []): LengthAwarePaginator
    {
        $search = $this->term($filters['search'] ?? null);

        return WritingTask::query()
            ->where('is_published', true)
            ->when($search !== null, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', $search)->orWhere('prompt', 'like', $search);
                });
            })
            ->when(($filters['exam_type'] ?? null) !== null && $filters['exam_type'] !== '', function ($query) use ($filters) {
                $query->where('exam_type', $filters['exam_type']);
            })
            ->when(($filters['task_type'] ?? null) !== null && $filters['task_type'] !== '', function ($query) use ($filters) {
                $query->where('task_type', $filters['task_type']);
            })
            ->orderBy('title')
            ->orderBy('id')
            ->paginate(12);
    }

    /**
     * @param  array{search?: string|null, exam_type?: string|null, task_type?: string|null}  $filters
     */
    public function paginateForStudent(User $user, array $filters = []): LengthAwarePaginator
    {
        if (! $this->practiceAccess->hasFullPracticeAccess($user)) {
            return WritingTask::query()->whereRaw('0 = 1')->paginate(12);
        }

        return $this->paginatePublished($filters);
    }

    public function find(WritingTask $task): WritingTask
    {
        return $task;
    }

    public function findPublished(int|string $id): WritingTask
    {
        return WritingTask::query()
            ->whereKey($id)
            ->where('is_published', true)
            ->firstOrFail();
    }

    public function findPublishedForStudent(User $user, int|string $id): WritingTask
    {
        $this->practiceAccess->assertFullPracticeAccess($user);

        return $this->findPublished($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): WritingTask
    {
        return WritingTask::query()->create($this->normalize($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(WritingTask $task, array $data): WritingTask
    {
        $task->update($this->normalize($data, $task));

        return $task->fresh();
    }

    public function publish(WritingTask $task, bool $published): WritingTask
    {
        $task->is_published = $published;
        $task->save();

        return $task->fresh();
    }

    public function delete(WritingTask $task): void
    {
        $task->delete();
    }

    public function storeImage(WritingTask $task, UploadedFile $file): WritingTask
    {
        $previous = $task->image_path;
        $path = $file->store('writing-images', 'public');
        $task->image_path = $path;
        $task->save();

        if ($previous) {
            Storage::disk('public')->delete($previous);
        }

        return $task->fresh();
    }

    public function destroyImage(WritingTask $task): WritingTask
    {
        if ($task->image_path) {
            Storage::disk('public')->delete($task->image_path);
            $task->image_path = null;
            $task->save();
        }

        return $task->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, ?WritingTask $existing = null): array
    {
        $taskType = $data['task_type'];
        $examType = $data['exam_type'];

        if ($taskType === WritingTask::TYPE_ACADEMIC_TASK_1 && $examType !== 'academic') {
            throw ValidationException::withMessages([
                'exam_type' => ['Academic Task 1 must use the academic exam type.'],
            ]);
        }

        if ($taskType === WritingTask::TYPE_GENERAL_TASK_1 && $examType !== 'general') {
            throw ValidationException::withMessages([
                'exam_type' => ['General Training Task 1 must use the general exam type.'],
            ]);
        }

        return [
            'exam_type' => $examType,
            'task_type' => $taskType,
            'title' => $data['title'],
            'instructions' => $data['instructions'] ?? null,
            'prompt' => $data['prompt'],
            'recommended_minutes' => (int) ($data['recommended_minutes'] ?? 20),
            'min_words' => (int) ($data['min_words'] ?? 150),
            'is_published' => (bool) ($data['is_published'] ?? ($existing?->is_published ?? false)),
        ];
    }

    private function term(?string $search): ?string
    {
        if ($search === null) {
            return null;
        }

        $search = trim($search);

        return $search === '' ? null : '%'.$search.'%';
    }
}
