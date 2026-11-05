<?php

namespace App\Services\Writing;

use App\Models\User;
use App\Models\WritingEvaluation;
use App\Models\WritingSubmission;
use App\Notifications\WritingEvaluationCompletedNotification;
use App\Services\Activity\ActivityLogService;
use App\Services\Settings\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class WritingEvaluationService
{
    public function __construct(
        private readonly ActivityLogService $activity,
        private readonly SettingService $settings,
    ) {}

    /**
     * Teachers with writing.evaluate may score submitted answers.
     * Without a separate assignment table, "assigned" means that permission.
     *
     * @param  array{search?: string|null, evaluation_status?: string|null, context?: string|null}  $filters
     */
    public function paginateForTeacher(array $filters): LengthAwarePaginator
    {
        $search = $this->term($filters['search'] ?? null);
        $status = $filters['evaluation_status'] ?? null;
        $context = $filters['context'] ?? null;

        return WritingSubmission::query()
            ->with([
                'task:id,title,exam_type,task_type,min_words',
                'user:id,name,email',
                'evaluation',
            ])
            ->where('status', WritingSubmission::STATUS_SUBMITTED)
            ->when($status === 'pending', fn ($query) => $query->whereDoesntHave('evaluation'))
            ->when($status === 'evaluated', fn ($query) => $query->whereHas('evaluation'))
            ->when($context === 'practice', fn ($query) => $query->where('context_key', WritingSubmission::CONTEXT_PRACTICE))
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

    public function showForTeacher(WritingSubmission $submission): WritingSubmission
    {
        if ($submission->status !== WritingSubmission::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'submission' => ['Only submitted writing answers can be evaluated.'],
            ]);
        }

        return $submission->load([
            'task',
            'user:id,name,email',
            'evaluation.teacher:id,name',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function evaluate(User $teacher, WritingSubmission $submission, array $data): WritingEvaluation
    {
        if (! $teacher->hasPermission('writing.evaluate')) {
            throw new AuthorizationException('You are not assigned to evaluate writing.');
        }

        if ($submission->status !== WritingSubmission::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'submission' => ['Only submitted writing answers can be evaluated.'],
            ]);
        }

        $existing = WritingEvaluation::query()
            ->where('writing_submission_id', $submission->id)
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'submission' => ['This writing submission already has an evaluation.'],
            ]);
        }

        $evaluation = WritingEvaluation::query()->create([
            'writing_submission_id' => $submission->id,
            'teacher_id' => $teacher->id,
            'task_response' => $data['task_response'],
            'coherence' => $data['coherence'],
            'lexical' => $data['lexical'],
            'grammar' => $data['grammar'],
            'overall_band' => $data['overall_band'],
            'feedback' => $data['feedback'] ?? null,
            'evaluated_at' => now(),
        ])->load('teacher:id,name');

        $this->activity->record(
            $teacher,
            'writing.evaluated',
            $evaluation,
            'Writing evaluation returned (band '.$evaluation->overall_band.').',
        );

        $submission->loadMissing(['user', 'task']);
        if ($this->settings->notificationsEnabled('evaluation') && $submission->user) {
            $submission->user->notify(new WritingEvaluationCompletedNotification($submission, $evaluation));
        }

        return $evaluation;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $teacher, WritingEvaluation $evaluation, array $data): WritingEvaluation
    {
        $canManage = $teacher->hasPermission('writing.manage');
        $isOwner = (int) $evaluation->teacher_id === (int) $teacher->id;

        if (! $canManage && ! $isOwner) {
            throw new AuthorizationException('You can only update evaluations you returned.');
        }

        if (! $teacher->hasPermission('writing.evaluate') && ! $canManage) {
            throw new AuthorizationException('You are not assigned to evaluate writing.');
        }

        $evaluation->fill([
            'task_response' => $data['task_response'],
            'coherence' => $data['coherence'],
            'lexical' => $data['lexical'],
            'grammar' => $data['grammar'],
            'overall_band' => $data['overall_band'],
            'feedback' => $data['feedback'] ?? null,
            'evaluated_at' => now(),
        ]);
        $evaluation->save();

        $this->activity->record(
            $teacher,
            'writing.evaluation_updated',
            $evaluation,
            'Writing evaluation updated (band '.$evaluation->overall_band.').',
        );

        return $evaluation->fresh('teacher:id,name');
    }

    public function showForStudent(User $student, WritingSubmission $submission): WritingSubmission
    {
        if ((int) $submission->user_id !== (int) $student->id) {
            throw new AuthorizationException('You can only view your own writing evaluations.');
        }

        return $submission->load(['task', 'evaluation.teacher:id,name']);
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
