<?php

namespace App\Services\Speaking;

use App\Models\SpeakingRecording;
use App\Models\SpeakingTask;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpeakingRecordingService
{
    public function __construct(private readonly SpeakingTaskService $tasks) {}

    public function forStudentTask(User $user, SpeakingTask $task): ?SpeakingRecording
    {
        $this->tasks->findPublished($task->id);

        return SpeakingRecording::query()
            ->with(['evaluation.teacher:id,name', 'task'])
            ->where('user_id', $user->id)
            ->where('speaking_task_id', $task->id)
            ->orderByDesc('id')
            ->first();
    }

    public function upload(User $user, SpeakingTask $task, UploadedFile $file, ?int $durationSeconds = null): SpeakingRecording
    {
        $task = $this->tasks->findPublished($task->id);

        $submitted = SpeakingRecording::query()
            ->where('user_id', $user->id)
            ->where('speaking_task_id', $task->id)
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
        ])->load(['task', 'evaluation']);
    }

    public function submit(User $user, SpeakingTask $task): SpeakingRecording
    {
        $this->tasks->findPublished($task->id);

        $recording = SpeakingRecording::query()
            ->where('user_id', $user->id)
            ->where('speaking_task_id', $task->id)
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

    private function assertOwner(User $user, SpeakingRecording $recording): void
    {
        if ((int) $recording->user_id !== (int) $user->id) {
            throw new AuthorizationException('You can only access your own speaking recordings.');
        }
    }
}
