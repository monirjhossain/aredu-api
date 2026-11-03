<?php

namespace App\Services\Writing;

use App\Models\User;
use App\Models\WritingSubmission;
use App\Models\WritingTask;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class WritingSubmissionService
{
    public function __construct(private readonly WritingTaskService $tasks) {}

    public function forStudentTask(User $user, WritingTask $task): WritingSubmission
    {
        $task = $this->tasks->findPublished($task->id);

        return WritingSubmission::query()->firstOrCreate(
            [
                'writing_task_id' => $task->id,
                'user_id' => $user->id,
            ],
            [
                'body' => '',
                'word_count' => 0,
                'status' => WritingSubmission::STATUS_DRAFT,
            ],
        )->loadMissing(['evaluation.teacher:id,name']);
    }

    /**
     * @param  array{body?: string|null}  $data
     */
    public function saveDraft(User $user, WritingTask $task, array $data): WritingSubmission
    {
        $submission = $this->forStudentTask($user, $task);
        $this->assertOwner($user, $submission);

        if ($submission->status === WritingSubmission::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'submission' => ['A submitted writing answer cannot be edited.'],
            ]);
        }

        $body = (string) ($data['body'] ?? '');
        $submission->body = $body;
        $submission->word_count = $this->countWords($body);
        $submission->status = WritingSubmission::STATUS_DRAFT;
        $submission->save();

        return $submission->fresh('task');
    }

    /**
     * @param  array{body?: string|null}  $data
     */
    public function submit(User $user, WritingTask $task, array $data): WritingSubmission
    {
        $submission = $this->forStudentTask($user, $task);
        $this->assertOwner($user, $submission);

        if ($submission->status === WritingSubmission::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'submission' => ['This writing task was already submitted.'],
            ]);
        }

        $body = array_key_exists('body', $data) ? (string) $data['body'] : (string) $submission->body;
        $wordCount = $this->countWords($body);
        $minWords = (int) $task->min_words;

        if (trim($body) === '') {
            throw ValidationException::withMessages([
                'body' => ['Write your answer before submitting.'],
            ]);
        }

        if ($wordCount < $minWords) {
            throw ValidationException::withMessages([
                'body' => ["Submit at least {$minWords} words. Current count: {$wordCount}."],
            ]);
        }

        $submission->body = $body;
        $submission->word_count = $wordCount;
        $submission->status = WritingSubmission::STATUS_SUBMITTED;
        $submission->submitted_at = now();
        $submission->save();

        return $submission->fresh('task');
    }

    public function paginateForStudent(User $user): LengthAwarePaginator
    {
        return WritingSubmission::query()
            ->with(['task', 'evaluation.teacher:id,name'])
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(12);
    }

    public function showOwned(User $user, WritingSubmission $submission): WritingSubmission
    {
        $this->assertOwner($user, $submission);

        return $submission->loadMissing(['task', 'evaluation.teacher:id,name']);
    }

    private function assertOwner(User $user, WritingSubmission $submission): void
    {
        if ((int) $submission->user_id !== (int) $user->id) {
            throw new AuthorizationException('You can only access your own writing submissions.');
        }
    }

    private function countWords(string $body): int
    {
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
        if ($body === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
