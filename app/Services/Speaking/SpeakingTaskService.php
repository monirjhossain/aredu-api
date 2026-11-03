<?php

namespace App\Services\Speaking;

use App\Models\SpeakingQuestion;
use App\Models\SpeakingTask;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SpeakingTaskService
{
    /**
     * @param  array{search?: string|null, part?: int|string|null, is_published?: mixed}  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $search = $this->term($filters['search'] ?? null);

        return SpeakingTask::query()
            ->withCount('questions')
            ->when($search !== null, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', $search)->orWhere('instructions', 'like', $search);
                });
            })
            ->when(($filters['part'] ?? null) !== null && $filters['part'] !== '', function ($query) use ($filters) {
                $query->where('part', (int) $filters['part']);
            })
            ->when(array_key_exists('is_published', $filters) && $filters['is_published'] !== null && $filters['is_published'] !== '', function ($query) use ($filters) {
                $query->where('is_published', $filters['is_published'] === '1' || $filters['is_published'] === 1 || $filters['is_published'] === true);
            })
            ->orderByDesc('id')
            ->paginate(15);
    }

    /**
     * @param  array{search?: string|null, part?: int|string|null}  $filters
     */
    public function paginatePublished(array $filters = []): LengthAwarePaginator
    {
        $search = $this->term($filters['search'] ?? null);

        return SpeakingTask::query()
            ->withCount('questions')
            ->where('is_published', true)
            ->when($search !== null, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', $search)->orWhere('instructions', 'like', $search);
                });
            })
            ->when(($filters['part'] ?? null) !== null && $filters['part'] !== '', function ($query) use ($filters) {
                $query->where('part', (int) $filters['part']);
            })
            ->orderBy('part')
            ->orderBy('title')
            ->orderBy('id')
            ->paginate(12);
    }

    public function find(SpeakingTask $task): SpeakingTask
    {
        return $task->load(['questions'])->loadCount('questions');
    }

    public function findPublished(int|string $id): SpeakingTask
    {
        return SpeakingTask::query()
            ->whereKey($id)
            ->where('is_published', true)
            ->with('questions')
            ->withCount('questions')
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): SpeakingTask
    {
        $normalized = $this->normalize($data);
        if ($normalized['is_published']) {
            throw ValidationException::withMessages([
                'is_published' => ['Add at least one question before publishing.'],
            ]);
        }

        $task = SpeakingTask::query()->create($normalized);

        return $this->find($task);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SpeakingTask $task, array $data): SpeakingTask
    {
        $normalized = $this->normalize($data, $task);
        if ($normalized['is_published'] && $task->questions()->count() === 0) {
            throw ValidationException::withMessages([
                'is_published' => ['Add at least one question before publishing.'],
            ]);
        }

        $task->update($normalized);

        return $this->find($task->fresh());
    }

    public function publish(SpeakingTask $task, bool $published): SpeakingTask
    {
        if ($published && $task->questions()->count() === 0) {
            throw ValidationException::withMessages([
                'is_published' => ['Add at least one question before publishing.'],
            ]);
        }

        $task->is_published = $published;
        $task->save();

        return $this->find($task);
    }

    public function delete(SpeakingTask $task): void
    {
        DB::transaction(function () use ($task) {
            $task->questions()->delete();
            $task->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function storeQuestion(SpeakingTask $task, array $data): SpeakingQuestion
    {
        $position = (int) ($data['position'] ?? (($task->questions()->max('position') ?? 0) + 1));

        return SpeakingQuestion::query()->create([
            'speaking_task_id' => $task->id,
            'prompt' => $data['prompt'],
            'cue_card' => $data['cue_card'] ?? null,
            'position' => max(1, $position),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateQuestion(SpeakingTask $task, SpeakingQuestion $question, array $data): SpeakingQuestion
    {
        $this->assertQuestionBelongs($task, $question);

        $question->prompt = $data['prompt'];
        $question->cue_card = $data['cue_card'] ?? null;
        if (array_key_exists('position', $data) && $data['position'] !== null) {
            $question->position = max(1, (int) $data['position']);
        }
        $question->save();

        return $question->fresh();
    }

    public function destroyQuestion(SpeakingTask $task, SpeakingQuestion $question): void
    {
        $this->assertQuestionBelongs($task, $question);
        $question->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, ?SpeakingTask $existing = null): array
    {
        return [
            'part' => (int) $data['part'],
            'title' => $data['title'],
            'instructions' => $data['instructions'] ?? null,
            'preparation_seconds' => (int) $data['preparation_seconds'],
            'response_seconds' => (int) $data['response_seconds'],
            'is_published' => (bool) ($data['is_published'] ?? ($existing?->is_published ?? false)),
        ];
    }

    private function assertQuestionBelongs(SpeakingTask $task, SpeakingQuestion $question): void
    {
        if ((int) $question->speaking_task_id !== (int) $task->id) {
            throw ValidationException::withMessages([
                'question' => ['This question does not belong to the speaking task.'],
            ]);
        }
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
