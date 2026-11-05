<?php

namespace App\Services\Writing;

use App\Models\FullMockExamSection;
use App\Models\User;
use App\Models\WritingSubmission;
use App\Models\WritingTask;
use App\Services\IELTS\FullMockSessionService;
use App\Support\ExamContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class WritingSubmissionService
{
    public function __construct(
        private readonly WritingTaskService $tasks,
        private readonly FullMockSessionService $fullMockSessions,
    ) {}

    /**
     * @param  string|null  $contextKey  "practice" (default) or "fmss:{sessionSectionId}" for full mock exams
     */
    public function forStudentTask(User $user, WritingTask $task, ?string $contextKey = null): WritingSubmission
    {
        $contextKey = ExamContext::normalize($contextKey);
        $task = $this->resolveTask($user, $task, $contextKey, false);

        return WritingSubmission::query()->firstOrCreate(
            [
                'writing_task_id' => $task->id,
                'user_id' => $user->id,
                'context_key' => $contextKey,
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
    public function saveDraft(User $user, WritingTask $task, array $data, ?string $contextKey = null): WritingSubmission
    {
        $contextKey = ExamContext::normalize($contextKey);
        $this->resolveTask($user, $task, $contextKey, true);
        $submission = $this->forStudentTask($user, $task, $contextKey);
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
    public function submit(User $user, WritingTask $task, array $data, ?string $contextKey = null): WritingSubmission
    {
        $contextKey = ExamContext::normalize($contextKey);
        $this->resolveTask($user, $task, $contextKey, true);
        $submission = $this->forStudentTask($user, $task, $contextKey);
        $this->assertOwner($user, $submission);

        if ($submission->status === WritingSubmission::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'submission' => ['This writing task was already submitted.'],
            ]);
        }

        $body = array_key_exists('body', $data) ? (string) $data['body'] : (string) $submission->body;
        $wordCount = $this->countWords($body);
        $minWords = (int) $task->min_words;

        if ($wordCount === 0) {
            throw ValidationException::withMessages([
                'body' => ['Write your answer before submitting.'],
            ]);
        }

        // In a timed full mock the student must be able to finish the section; the minimum is only a practice nudge.
        if ($contextKey === WritingSubmission::CONTEXT_PRACTICE && $wordCount < $minWords) {
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

    /**
     * Practice: enrollment-gated published task. Full mock: session ownership/time is verified instead
     * (access to the exam itself was checked when the session started).
     */
    private function resolveTask(User $user, WritingTask $task, string $contextKey, bool $write): WritingTask
    {
        if ($contextKey === WritingSubmission::CONTEXT_PRACTICE) {
            return $this->tasks->findPublishedForStudent($user, $task->id);
        }

        $this->fullMockSessions->assertContextOwned($user, $contextKey, FullMockExamSection::SKILL_WRITING, $task->id, $write);

        return $this->tasks->findPublished($task->id);
    }

    private function assertOwner(User $user, WritingSubmission $submission): void
    {
        if ((int) $submission->user_id !== (int) $user->id) {
            throw new AuthorizationException('You can only access your own writing submissions.');
        }
    }

    private function countWords(string $body): int
    {
        // Allow limited HTML from the rich editor; count plain text only.
        $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
        if ($body === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
