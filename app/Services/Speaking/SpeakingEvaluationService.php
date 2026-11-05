<?php

namespace App\Services\Speaking;

use App\Models\SpeakingEvaluation;
use App\Models\SpeakingRecording;
use App\Models\User;
use App\Notifications\SpeakingEvaluationCompletedNotification;
use App\Services\Activity\ActivityLogService;
use App\Services\Settings\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class SpeakingEvaluationService
{
    public function __construct(
        private readonly ActivityLogService $activity,
        private readonly SettingService $settings,
    ) {}

    /**
     * @param  array{search?: string|null, evaluation_status?: string|null, context?: string|null}  $filters
     */
    public function paginateForTeacher(array $filters): LengthAwarePaginator
    {
        $search = $this->term($filters['search'] ?? null);
        $status = $filters['evaluation_status'] ?? null;
        $context = $filters['context'] ?? null;

        return SpeakingRecording::query()
            ->with([
                'task:id,title,part,preparation_seconds,response_seconds',
                'user:id,name,email',
                'evaluation',
            ])
            ->where('status', SpeakingRecording::STATUS_SUBMITTED)
            ->when($status === 'pending', fn ($query) => $query->whereDoesntHave('evaluation'))
            ->when($status === 'evaluated', fn ($query) => $query->whereHas('evaluation'))
            ->when($context === 'practice', fn ($query) => $query->where('context_key', SpeakingRecording::CONTEXT_PRACTICE))
            ->when($context === 'full_mock', fn ($query) => $query->where('context_key', 'like', 'fmss:%'))
            ->when($search !== null, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->whereHas('task', fn ($task) => $task->where('title', 'like', $search))
                        ->orWhereHas('user', function ($user) use ($search) {
                            $user->where('name', 'like', $search)->orWhere('email', 'like', $search);
                        });
                });
            })
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(15);
    }

    public function showForTeacher(SpeakingRecording $recording): SpeakingRecording
    {
        if ($recording->status !== SpeakingRecording::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'recording' => ['Only submitted speaking recordings can be evaluated.'],
            ]);
        }

        return $recording->load([
            'task.questions',
            'user:id,name,email',
            'evaluation.teacher:id,name',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function evaluate(User $teacher, SpeakingRecording $recording, array $data): SpeakingEvaluation
    {
        if (! $teacher->hasPermission('speaking.evaluate')) {
            throw new AuthorizationException('You are not assigned to evaluate speaking.');
        }

        if ($recording->status !== SpeakingRecording::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'recording' => ['Only submitted speaking recordings can be evaluated.'],
            ]);
        }

        $existing = SpeakingEvaluation::query()
            ->where('speaking_recording_id', $recording->id)
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'recording' => ['This speaking recording already has an evaluation.'],
            ]);
        }

        $evaluation = SpeakingEvaluation::query()->create([
            'speaking_recording_id' => $recording->id,
            'teacher_id' => $teacher->id,
            'fluency' => $data['fluency'],
            'lexical' => $data['lexical'],
            'grammar' => $data['grammar'],
            'pronunciation' => $data['pronunciation'],
            'overall_band' => $data['overall_band'],
            'feedback' => $data['feedback'] ?? null,
            'evaluated_at' => now(),
        ])->load('teacher:id,name');

        $this->activity->record(
            $teacher,
            'speaking.evaluated',
            $evaluation,
            'Speaking evaluation returned (band '.$evaluation->overall_band.').',
        );

        $recording->loadMissing(['user', 'task']);
        if ($this->settings->notificationsEnabled('evaluation') && $recording->user) {
            $recording->user->notify(new SpeakingEvaluationCompletedNotification($recording, $evaluation));
        }

        return $evaluation;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $teacher, SpeakingEvaluation $evaluation, array $data): SpeakingEvaluation
    {
        $canManage = $teacher->hasPermission('speaking.manage');
        $isOwner = (int) $evaluation->teacher_id === (int) $teacher->id;

        if (! $canManage && ! $isOwner) {
            throw new AuthorizationException('You can only update evaluations you returned.');
        }

        if (! $teacher->hasPermission('speaking.evaluate') && ! $canManage) {
            throw new AuthorizationException('You are not assigned to evaluate speaking.');
        }

        $evaluation->fill([
            'fluency' => $data['fluency'],
            'lexical' => $data['lexical'],
            'grammar' => $data['grammar'],
            'pronunciation' => $data['pronunciation'],
            'overall_band' => $data['overall_band'],
            'feedback' => $data['feedback'] ?? null,
            'evaluated_at' => now(),
        ]);
        $evaluation->save();

        $this->activity->record(
            $teacher,
            'speaking.evaluation_updated',
            $evaluation,
            'Speaking evaluation updated (band '.$evaluation->overall_band.').',
        );

        return $evaluation->fresh('teacher:id,name');
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
