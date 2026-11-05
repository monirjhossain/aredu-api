<?php

namespace App\Services\Speaking;

use App\Models\FullMockExamSection;
use App\Models\SpeakingRecording;
use App\Models\SpeakingTask;
use App\Models\User;
use App\Services\IELTS\FullMockSessionService;
use App\Support\ExamContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpeakingRecordingService
{
    public function __construct(
        private readonly SpeakingTaskService $tasks,
        private readonly FullMockSessionService $fullMockSessions,
    ) {}

    /**
     * @param  string|null  $contextKey  "practice" (default) or "fmss:{sessionSectionId}" for full mock exams
     */
    public function forStudentTask(User $user, SpeakingTask $task, ?string $contextKey = null): ?SpeakingRecording
    {
        $contextKey = ExamContext::normalize($contextKey);
        $this->resolveTask($user, $task, $contextKey, false);

        return SpeakingRecording::query()
            ->with(['evaluation.teacher:id,name', 'task'])
            ->where('user_id', $user->id)
            ->where('speaking_task_id', $task->id)
            ->where('context_key', $contextKey)
            ->orderByDesc('id')
            ->first();
    }

    public function upload(User $user, SpeakingTask $task, UploadedFile $file, ?int $durationSeconds = null, ?string $contextKey = null): SpeakingRecording
    {
        $contextKey = ExamContext::normalize($contextKey);
        $task = $this->resolveTask($user, $task, $contextKey, true);

        $submitted = SpeakingRecording::query()
            ->where('user_id', $user->id)
            ->where('speaking_task_id', $task->id)
            ->where('context_key', $contextKey)
            ->where('status', SpeakingRecording::STATUS_SUBMITTED)
            ->first();

        if ($submitted !== null) {
            throw ValidationException::withMessages([
                'recording' => ['This speaking task was already submitted.'],
            ]);
        }

        $draft = SpeakingRecording::query()
            ->where('user_id', $user->id)
            ->where('speaking_task_id', $task->id)
            ->where('context_key', $contextKey)
            ->where('status', SpeakingRecording::STATUS_UPLOADED)
            ->orderByDesc('id')
            ->first();

        $path = $file->store('speaking-recordings/'.$task->id.'/'.$user->id, 'local');
        $mime = $file->getMimeType() ?: 'audio/webm';
        $size = $file->getSize() ?: 0;

        if ($draft !== null) {
            Storage::disk($draft->disk)->delete($draft->path);
            $draft->path = $path;
            $draft->disk = 'local';
            $draft->mime = $mime;
            $draft->size_bytes = $size;
            $draft->duration_seconds = $durationSeconds;
            $draft->save();

            return $draft->fresh(['task', 'evaluation']);
        }

        return SpeakingRecording::query()->create([
            'speaking_task_id' => $task->id,
            'user_id' => $user->id,
            'disk' => 'local',
            'path' => $path,
            'mime' => $mime,
            'size_bytes' => $size,
            'duration_seconds' => $durationSeconds,
            'status' => SpeakingRecording::STATUS_UPLOADED,
            'context_key' => $contextKey,
        ])->load(['task', 'evaluation']);
    }

    public function submit(User $user, SpeakingTask $task, ?string $contextKey = null): SpeakingRecording
    {
        $contextKey = ExamContext::normalize($contextKey);
        $this->resolveTask($user, $task, $contextKey, true);

        $recording = SpeakingRecording::query()
            ->where('user_id', $user->id)
            ->where('speaking_task_id', $task->id)
            ->where('context_key', $contextKey)
            ->orderByDesc('id')
            ->first();

        if ($recording === null) {
            throw ValidationException::withMessages([
                'recording' => ['Upload a recording before submitting.'],
            ]);
        }

        if ($recording->status === SpeakingRecording::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'recording' => ['This speaking task was already submitted.'],
            ]);
        }

        if ($recording->status !== SpeakingRecording::STATUS_UPLOADED || ! Storage::disk($recording->disk)->exists($recording->path)) {
            throw ValidationException::withMessages([
                'recording' => ['Upload a recording before submitting.'],
            ]);
        }

        $recording->status = SpeakingRecording::STATUS_SUBMITTED;
        $recording->submitted_at = now();
        $recording->save();

        return $recording->fresh(['task', 'evaluation']);
    }

    public function paginateForStudent(User $user): LengthAwarePaginator
    {
        return SpeakingRecording::query()
            ->with(['task', 'evaluation.teacher:id,name'])
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(12);
    }

    public function showOwned(User $user, SpeakingRecording $recording): SpeakingRecording
    {
        $this->assertOwner($user, $recording);

        return $recording->loadMissing(['task.questions', 'evaluation.teacher:id,name']);
    }

    public function stream(User $user, SpeakingRecording $recording): StreamedResponse
    {
        $canEvaluate = $user->hasPermission('speaking.evaluate');
        $isOwner = (int) $recording->user_id === (int) $user->id;

        if (! $isOwner && ! $canEvaluate) {
            throw new AuthorizationException('You cannot access this speaking recording.');
        }

        if ($canEvaluate && ! $isOwner && $recording->status !== SpeakingRecording::STATUS_SUBMITTED) {
            throw new AuthorizationException('Only submitted recordings are available for evaluation.');
        }

        if (! Storage::disk($recording->disk)->exists($recording->path)) {
            throw ValidationException::withMessages([
                'recording' => ['Recording file is missing.'],
            ]);
        }

        return Storage::disk($recording->disk)->response(
            $recording->path,
            'speaking-'.$recording->id,
            [
                'Content-Type' => $recording->mime ?: 'audio/webm',
                'Content-Disposition' => 'inline',
            ],
        );
    }

    /**
     * Practice: enrollment-gated published task. Full mock: session ownership/time is verified instead
     * (access to the exam itself was checked when the session started).
     */
    private function resolveTask(User $user, SpeakingTask $task, string $contextKey, bool $write): SpeakingTask
    {
        if ($contextKey === SpeakingRecording::CONTEXT_PRACTICE) {
            return $this->tasks->findPublishedForStudent($user, $task->id);
        }

        $this->fullMockSessions->assertContextOwned($user, $contextKey, FullMockExamSection::SKILL_SPEAKING, $task->id, $write);

        return $this->tasks->findPublished($task->id);
    }

    private function assertOwner(User $user, SpeakingRecording $recording): void
    {
        if ((int) $recording->user_id !== (int) $user->id) {
            throw new AuthorizationException('You can only access your own speaking recordings.');
        }
    }
}
