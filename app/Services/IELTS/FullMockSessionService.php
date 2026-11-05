<?php

namespace App\Services\IELTS;

use App\Models\FullMockExam;
use App\Models\FullMockExamSection;
use App\Models\FullMockSession;
use App\Models\FullMockSessionSection;
use App\Models\SpeakingRecording;
use App\Models\TestAttempt;
use App\Models\User;
use App\Models\WritingSubmission;
use App\Services\MockTest\ListeningAttemptService;
use App\Services\MockTest\ReadingAttemptService;
use App\Services\Practice\PracticeAccessService;
use App\Support\ExamContext;
use App\Support\IeltsOverallBand;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Student orchestrator for a full IELTS mock (Listening → Reading → Writing → Speaking, in exam order).
 *
 * Each skill artifact is isolated with context_key = "fmss:{session_section_id}" so practice attempts
 * and exam attempts never collide. Controllers that expose skill endpoints with a context_key MUST call
 * assertContextOwned() (the Writing/Speaking services already do so internally).
 */
class FullMockSessionService
{
    /**
     * Writing/Speaking accept late saves for a few seconds after the timer to absorb network latency.
     * Reading/Listening attempts are strict (their own services reject writes after must_submit_at).
     */
    public const WRITE_GRACE_SECONDS = 15;

    public function __construct(
        private readonly PracticeAccessService $practiceAccess,
        private readonly ReadingAttemptService $reading,
        private readonly ListeningAttemptService $listening,
    ) {}

    /**
     * Start a new session or resume the active one for this exam.
     *
     * @return array<string, mixed> session payload plus meta.resumed
     */
    public function startOrResume(User $user, FullMockExam $exam): array
    {
        $exam = FullMockExam::query()
            ->whereKey($exam->id)
            ->where('is_published', true)
            ->first();

        if ($exam === null) {
            throw (new ModelNotFoundException)->setModel(FullMockExam::class);
        }

        $this->practiceAccess->assertCanAccessFullMock($user, $exam);

        return DB::transaction(function () use ($user, $exam) {
            // Serialize concurrent starts per student (a gap lock on "no rows" is not reliable).
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $existing = FullMockSession::query()
                ->where('user_id', $user->id)
                ->where('full_mock_exam_id', $exam->id)
                ->where('status', FullMockSession::STATUS_IN_PROGRESS)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing = $this->reconcileLocked($existing);

                if ($existing->status === FullMockSession::STATUS_IN_PROGRESS) {
                    if (! $exam->allow_resume) {
                        throw ValidationException::withMessages([
                            'exam' => ['You already have an exam in progress and this exam cannot be resumed.'],
                        ]);
                    }

                    return $this->payload($existing) + ['meta' => ['resumed' => true]];
                }
            }

            $examSections = FullMockExamSection::query()
                ->where('full_mock_exam_id', $exam->id)
                ->with(['mockTest', 'writingTask', 'speakingTask'])
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            if ($examSections->isEmpty()) {
                throw ValidationException::withMessages([
                    'exam' => ['This exam has no sections yet.'],
                ]);
            }

            foreach ($examSections as $examSection) {
                $this->assertSourcePublished($examSection);
            }

            $session = FullMockSession::query()->create([
                'uuid' => (string) Str::uuid(),
                'full_mock_exam_id' => $exam->id,
                'user_id' => $user->id,
                'status' => FullMockSession::STATUS_IN_PROGRESS,
                'current_position' => (int) $examSections->first()->position,
                'started_at' => now(),
            ]);

            foreach ($examSections->values() as $index => $examSection) {
                FullMockSessionSection::query()->create([
                    'full_mock_session_id' => $session->id,
                    'full_mock_exam_section_id' => $examSection->id,
                    'skill' => $examSection->skill,
                    'title' => $examSection->title,
                    'position' => $examSection->position,
                    'duration_seconds' => $this->resolveDuration($examSection),
                    'status' => $index === 0
                        ? FullMockSessionSection::STATUS_READY
                        : FullMockSessionSection::STATUS_LOCKED,
                ]);
            }

            return $this->payload($session) + ['meta' => ['resumed' => false]];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, string $uuid): array
    {
        $session = $this->ownedSession($user, $uuid);
        $session = $this->reconcile($session);

        return $this->payload($session);
    }

    /**
     * Unlock/start the current section and prepare its skill artifact (idempotent for in-progress sections).
     *
     * @return array<string, mixed> session payload plus "navigation"
     */
    public function enterSection(User $user, string $uuid, int $sessionSectionId): array
    {
        $owned = $this->ownedSession($user, $uuid);

        return DB::transaction(function () use ($user, $owned, $sessionSectionId) {
            $session = $this->lockSession($owned);
            $session = $this->reconcileLocked($session);

            if ($session->status !== FullMockSession::STATUS_IN_PROGRESS) {
                throw ValidationException::withMessages([
                    'session' => ['This exam session is no longer active.'],
                ]);
            }

            $section = FullMockSessionSection::query()
                ->where('full_mock_session_id', $session->id)
                ->whereKey($sessionSectionId)
                ->lockForUpdate()
                ->with(['examSection.mockTest', 'examSection.writingTask', 'examSection.speakingTask'])
                ->first();

            if ($section === null) {
                throw (new ModelNotFoundException)->setModel(FullMockSessionSection::class, [$sessionSectionId]);
            }

            if (! in_array($section->status, [FullMockSessionSection::STATUS_READY, FullMockSessionSection::STATUS_IN_PROGRESS], true)
                || (int) $section->position !== (int) $session->current_position) {
                throw ValidationException::withMessages([
                    'section' => ['This section is not available yet.'],
                ]);
            }

            $examSection = $section->examSection;
            if ($examSection === null) {
                throw ValidationException::withMessages([
                    'section' => ['This section is no longer available.'],
                ]);
            }
            $this->assertSourcePublished($examSection);

            if ($section->status === FullMockSessionSection::STATUS_READY) {
                $startedAt = now();
                $section->status = FullMockSessionSection::STATUS_IN_PROGRESS;
                $section->started_at = $startedAt;
                $section->must_submit_at = $section->duration_seconds
                    ? $startedAt->copy()->addSeconds((int) $section->duration_seconds)
                    : null;
                $section->save();
            }

            $contextKey = $section->contextKey();
            $user = $session->user ?? $user;

            switch ($section->skill) {
                case FullMockExamSection::SKILL_LISTENING:
                case FullMockExamSection::SKILL_READING:
                    if ($section->test_attempt_id === null) {
                        $service = $section->skill === FullMockExamSection::SKILL_READING ? $this->reading : $this->listening;
                        $result = $service->startOrResumeForContext(
                            $user,
                            $examSection->mockTest,
                            $contextKey,
                            $section->duration_seconds ? (int) $section->duration_seconds : null,
                        );
                        $attempt = TestAttempt::query()->findOrFail((int) $result['attempt']['id']);
                        $section->test_attempt_id = $attempt->id;
                        // The attempt is the source of truth for the timer.
                        $section->started_at = $attempt->started_at;
                        $section->must_submit_at = $attempt->must_submit_at;
                        $section->save();
                    }
                    break;

                case FullMockExamSection::SKILL_WRITING:
                    if ($section->writing_submission_id === null) {
                        $submission = WritingSubmission::query()->firstOrCreate(
                            [
                                'user_id' => $user->id,
                                'writing_task_id' => $examSection->writing_task_id,
                                'context_key' => $contextKey,
                            ],
                            [
                                'body' => '',
                                'word_count' => 0,
                                'status' => WritingSubmission::STATUS_DRAFT,
                            ],
                        );
                        $section->writing_submission_id = $submission->id;
                        $section->save();
                    }
                    break;

                default:
                    $recording = $this->latestRecording($user, $section, $examSection);
                    if ($recording !== null && $section->speaking_recording_id !== $recording->id) {
                        $section->speaking_recording_id = $recording->id;
                        $section->save();
                    }
                    break;
            }

            $session->unsetRelation('sections');

            return $this->payload($session, $this->navigation($session, $section->fresh(['examSection'])));
        });
    }

    /**
     * Confirm a section is finished (artifact submitted, or timer expired) and advance the session.
     *
     * @return array<string, mixed>
     */
    public function completeSection(User $user, string $uuid, int $sessionSectionId): array
    {
        $owned = $this->ownedSession($user, $uuid);

        return DB::transaction(function () use ($owned, $sessionSectionId) {
            $session = $this->lockSession($owned);
            $session = $this->reconcileLocked($session);

            $section = FullMockSessionSection::query()
                ->where('full_mock_session_id', $session->id)
                ->whereKey($sessionSectionId)
                ->lockForUpdate()
                ->first();

            if ($section === null) {
                throw (new ModelNotFoundException)->setModel(FullMockSessionSection::class, [$sessionSectionId]);
            }

            // Reconcile already auto-finishes submitted sections; refresh after sync.
            if (in_array($section->status, [FullMockSessionSection::STATUS_COMPLETED, FullMockSessionSection::STATUS_EXPIRED], true)) {
                return $this->payload($session->fresh());
            }

            if ($section->status !== FullMockSessionSection::STATUS_IN_PROGRESS) {
                throw ValidationException::withMessages([
                    'section' => ['This section has not been started.'],
                ]);
            }

            // Force one more sync for the requested section (covers race after just-submitted answers).
            $session->loadMissing('user');
            $section->loadMissing('examSection');
            $this->syncSection($session, $section);
            $sections = FullMockSessionSection::query()
                ->where('full_mock_session_id', $session->id)
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $this->advance($session, $sections);
            $section->refresh();

            if (in_array($section->status, [FullMockSessionSection::STATUS_COMPLETED, FullMockSessionSection::STATUS_EXPIRED], true)) {
                return $this->payload($session->fresh());
            }

            $message = match ($section->skill) {
                FullMockExamSection::SKILL_LISTENING, FullMockExamSection::SKILL_READING => 'Submit your answers before finishing this section.',
                FullMockExamSection::SKILL_WRITING => 'Submit your writing answer before finishing this section.',
                default => 'Submit your speaking recording before finishing this section.',
            };

            throw ValidationException::withMessages(['section' => [$message]]);
        });
    }

    /**
     * Light timer poll: reconciles expiry and reports remaining time for the current section.
     *
     * @return array<string, mixed>
     */
    public function heartbeat(User $user, string $uuid): array
    {
        $session = $this->ownedSession($user, $uuid);
        $session = $this->reconcile($session);
        $sections = $session->sections()->get();
        $current = $sections->firstWhere('status', FullMockSessionSection::STATUS_IN_PROGRESS);

        return [
            'server_now' => now()->toIso8601String(),
            'session' => [
                'uuid' => $session->uuid,
                'status' => $session->status,
                'current_position' => $session->current_position,
                'completed_at' => $session->completed_at?->toIso8601String(),
            ],
            'seconds_remaining' => $current !== null ? $this->secondsRemaining($current) : null,
            'current_section' => $current !== null ? [
                'id' => $current->id,
                'skill' => $current->skill,
                'status' => $current->status,
                'must_submit_at' => $current->must_submit_at?->toIso8601String(),
                'seconds_remaining' => $this->secondsRemaining($current),
            ] : null,
            'sections' => $sections->map(fn (FullMockSessionSection $section) => [
                'id' => $section->id,
                'status' => $section->status,
                'seconds_remaining' => $this->secondsRemaining($section),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function abandon(User $user, string $uuid): array
    {
        $owned = $this->ownedSession($user, $uuid);

        return DB::transaction(function () use ($owned) {
            $session = $this->lockSession($owned);

            if ($session->status === FullMockSession::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'session' => ['A completed exam cannot be abandoned.'],
                ]);
            }

            if ($session->status === FullMockSession::STATUS_IN_PROGRESS) {
                $session->status = FullMockSession::STATUS_ABANDONED;
                $session->save();
            }

            return $this->payload($session);
        });
    }

    /**
     * Sync section state with the linked attempt/submission/recording and timers, then advance the session.
     */
    public function reconcile(FullMockSession $session): FullMockSession
    {
        return DB::transaction(function () use ($session) {
            return $this->reconcileLocked($this->lockSession($session));
        });
    }

    /**
     * Verify that an exam context belongs to the student (and optionally the right skill/task/time window).
     * Returns the session section for fmss contexts, null for plain practice.
     *
     * @param  bool  $requireActive  true for write operations (save/submit/upload): the section must be in progress and within time
     */
    public function assertContextOwned(
        User $user,
        string $contextKey,
        ?string $skill = null,
        ?int $taskId = null,
        bool $requireActive = false,
    ): ?FullMockSessionSection {
        $contextKey = ExamContext::normalize($contextKey);
        $sectionId = ExamContext::sessionSectionId($contextKey);

        if ($sectionId === null) {
            return null;
        }

        $section = FullMockSessionSection::query()
            ->with(['session', 'examSection'])
            ->find($sectionId);

        if ($section === null || $section->session === null) {
            throw (new ModelNotFoundException)->setModel(FullMockSessionSection::class, [$sectionId]);
        }

        if ((int) $section->session->user_id !== (int) $user->id) {
            throw new AuthorizationException('You can only access your own exam sections.');
        }

        if ($skill !== null && $section->skill !== $skill) {
            throw ValidationException::withMessages([
                'context_key' => ['This exam section does not match the requested skill.'],
            ]);
        }

        if ($taskId !== null) {
            $expected = match ($section->skill) {
                FullMockExamSection::SKILL_WRITING => $section->examSection?->writing_task_id,
                FullMockExamSection::SKILL_SPEAKING => $section->examSection?->speaking_task_id,
                default => $section->examSection?->mock_test_id,
            };

            if ((int) $expected !== $taskId) {
                throw ValidationException::withMessages([
                    'context_key' => ['This task is not part of the selected exam section.'],
                ]);
            }
        }

        if ($requireActive) {
            if ($section->session->status !== FullMockSession::STATUS_IN_PROGRESS
                || $section->status !== FullMockSessionSection::STATUS_IN_PROGRESS) {
                throw ValidationException::withMessages([
                    'context_key' => ['This exam section is not active.'],
                ]);
            }

            if ($section->must_submit_at !== null
                && $section->must_submit_at->copy()->addSeconds(self::WRITE_GRACE_SECONDS)->lte(now())) {
                throw ValidationException::withMessages([
                    'context_key' => ['Time expired for this exam section.'],
                ]);
            }
        }

        return $section;
    }

    // ---------------------------------------------------------------------
    // Reconciliation internals (callers must hold the session row lock)
    // ---------------------------------------------------------------------

    private function reconcileLocked(FullMockSession $session): FullMockSession
    {
        if ($session->status !== FullMockSession::STATUS_IN_PROGRESS) {
            return $session;
        }

        $session->loadMissing('user');
        $sections = FullMockSessionSection::query()
            ->where('full_mock_session_id', $session->id)
            ->with('examSection')
            ->orderBy('position')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($sections as $section) {
            if ($section->status === FullMockSessionSection::STATUS_IN_PROGRESS) {
                $this->syncSection($session, $section);
            }
        }

        $this->advance($session, $sections);
        $session->unsetRelation('sections');

        return $session;
    }

    private function syncSection(FullMockSession $session, FullMockSessionSection $section): void
    {
        $user = $session->user;
        $contextKey = $section->contextKey();
        $examSection = $section->examSection;

        switch ($section->skill) {
            case FullMockExamSection::SKILL_LISTENING:
            case FullMockExamSection::SKILL_READING:
                $attempt = $section->test_attempt_id !== null
                    ? TestAttempt::query()->find($section->test_attempt_id)
                    : TestAttempt::query()
                        ->where('user_id', $user->id)
                        ->where('context_key', $contextKey)
                        ->orderByDesc('id')
                        ->first();

                if ($attempt !== null && $section->test_attempt_id !== $attempt->id) {
                    $section->test_attempt_id = $attempt->id;
                    $section->save();
                }

                if ($attempt === null) {
                    if ($this->isPastDeadline($section, 0)) {
                        $this->finish($section, FullMockSessionSection::STATUS_EXPIRED, now());
                    }
                    break;
                }

                if ($attempt->status === TestAttempt::STATUS_IN_PROGRESS
                    && $attempt->must_submit_at !== null
                    && $attempt->must_submit_at->lte(now())) {
                    $service = $section->skill === FullMockExamSection::SKILL_READING ? $this->reading : $this->listening;
                    $attempt = $service->finalizeOwnedAttempt($user, $attempt);
                }

                if ($attempt->status === TestAttempt::STATUS_SUBMITTED) {
                    $this->finishFromSubmission($section, $attempt->submitted_at ?? now(), 0, $attempt->must_submit_at);
                }
                break;

            case FullMockExamSection::SKILL_WRITING:
                $submission = $section->writing_submission_id !== null
                    ? WritingSubmission::query()->find($section->writing_submission_id)
                    : WritingSubmission::query()
                        ->where('user_id', $user->id)
                        ->where('context_key', $contextKey)
                        ->orderByDesc('id')
                        ->first();

                if ($submission !== null && $section->writing_submission_id !== $submission->id) {
                    $section->writing_submission_id = $submission->id;
                    $section->save();
                }

                if ($submission !== null && $submission->status === WritingSubmission::STATUS_SUBMITTED) {
                    $this->finishFromSubmission($section, $submission->submitted_at ?? now(), self::WRITE_GRACE_SECONDS);
                    break;
                }

                if ($this->isPastDeadline($section, self::WRITE_GRACE_SECONDS)) {
                    $submission = $this->autoSubmitWriting($user, $section, $examSection, $contextKey);
                    $section->writing_submission_id = $submission->id;
                    $this->finish($section, FullMockSessionSection::STATUS_EXPIRED, now());
                }
                break;

            default:
                $recording = $this->latestRecording($user, $section, $examSection);

                if ($recording !== null && $section->speaking_recording_id !== $recording->id) {
                    $section->speaking_recording_id = $recording->id;
                    $section->save();
                }

                if ($recording !== null && $recording->status === SpeakingRecording::STATUS_SUBMITTED) {
                    $this->finishFromSubmission($section, $recording->submitted_at ?? now(), self::WRITE_GRACE_SECONDS);
                    break;
                }

                if ($this->isPastDeadline($section, self::WRITE_GRACE_SECONDS)) {
                    // An uploaded-but-unsubmitted recording is submitted on the student's behalf.
                    if ($recording !== null && $recording->status === SpeakingRecording::STATUS_UPLOADED) {
                        $recording->status = SpeakingRecording::STATUS_SUBMITTED;
                        $recording->submitted_at = now();
                        $recording->save();
                    }
                    $this->finish($section, FullMockSessionSection::STATUS_EXPIRED, now());
                }
                break;
        }
    }

    /**
     * Unlock the next section, move the pointer, and complete the session after the last section.
     *
     * @param  Collection<int, FullMockSessionSection>  $sections
     */
    private function advance(FullMockSession $session, Collection $sections): void
    {
        $done = [FullMockSessionSection::STATUS_COMPLETED, FullMockSessionSection::STATUS_EXPIRED];
        $pending = $sections->first(fn (FullMockSessionSection $section) => ! in_array($section->status, $done, true));

        if ($pending === null) {
            $lastDone = $sections->max(fn (FullMockSessionSection $section) => $section->completed_at?->getTimestamp() ?? 0);
            $session->status = FullMockSession::STATUS_COMPLETED;
            $session->completed_at = $lastDone ? Carbon::createFromTimestamp($lastDone) : now();
            $session->current_position = (int) ($sections->last()?->position ?? $session->current_position);
            $session->save();

            return;
        }

        if ($pending->status === FullMockSessionSection::STATUS_LOCKED) {
            $pending->status = FullMockSessionSection::STATUS_READY;
            $pending->save();
        }

        if ((int) $session->current_position !== (int) $pending->position) {
            $session->current_position = (int) $pending->position;
            $session->save();
        }
    }

    private function finishFromSubmission(FullMockSessionSection $section, Carbon $submittedAt, int $graceSeconds, ?Carbon $deadline = null): void
    {
        $deadline ??= $section->must_submit_at;
        $late = $deadline !== null
            && $submittedAt->gt($deadline->copy()->addSeconds($graceSeconds));

        $this->finish(
            $section,
            $late ? FullMockSessionSection::STATUS_EXPIRED : FullMockSessionSection::STATUS_COMPLETED,
            $submittedAt,
        );
    }

    private function finish(FullMockSessionSection $section, string $status, Carbon $at): void
    {
        $section->status = $status;
        $section->completed_at = $at;
        $section->save();
    }

    private function isPastDeadline(FullMockSessionSection $section, int $graceSeconds): bool
    {
        return $section->must_submit_at !== null
            && $section->must_submit_at->copy()->addSeconds($graceSeconds)->lte(now());
    }

    private function autoSubmitWriting(User $user, FullMockSessionSection $section, ?FullMockExamSection $examSection, string $contextKey): WritingSubmission
    {
        $submission = WritingSubmission::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'writing_task_id' => $examSection?->writing_task_id,
                'context_key' => $contextKey,
            ],
            [
                'body' => '',
                'word_count' => 0,
                'status' => WritingSubmission::STATUS_DRAFT,
            ],
        );

        if ($submission->status !== WritingSubmission::STATUS_SUBMITTED) {
            $submission->status = WritingSubmission::STATUS_SUBMITTED;
            $submission->submitted_at = now();
            $submission->save();
        }

        return $submission;
    }

    private function latestRecording(User $user, FullMockSessionSection $section, ?FullMockExamSection $examSection): ?SpeakingRecording
    {
        if ($section->speaking_recording_id !== null) {
            return SpeakingRecording::query()->find($section->speaking_recording_id);
        }

        if ($examSection === null) {
            return null;
        }

        return SpeakingRecording::query()
            ->where('user_id', $user->id)
            ->where('speaking_task_id', $examSection->speaking_task_id)
            ->where('context_key', $section->contextKey())
            ->orderByDesc('id')
            ->first();
    }

    // ---------------------------------------------------------------------
    // Lookup / guards
    // ---------------------------------------------------------------------

    private function ownedSession(User $user, string $uuid): FullMockSession
    {
        $session = FullMockSession::query()->where('uuid', $uuid)->first();

        if ($session === null) {
            throw (new ModelNotFoundException)->setModel(FullMockSession::class, [$uuid]);
        }

        if ((int) $session->user_id !== (int) $user->id) {
            throw new AuthorizationException('You can only access your own exam sessions.');
        }

        return $session;
    }

    private function lockSession(FullMockSession $session): FullMockSession
    {
        return FullMockSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
    }

    private function assertSourcePublished(FullMockExamSection $examSection): void
    {
        $ok = match ($examSection->skill) {
            FullMockExamSection::SKILL_LISTENING, FullMockExamSection::SKILL_READING => $examSection->mockTest !== null
                && $examSection->mockTest->is_published
                && $examSection->mockTest->module === $examSection->skill,
            FullMockExamSection::SKILL_WRITING => $examSection->writingTask !== null && $examSection->writingTask->is_published,
            default => $examSection->speakingTask !== null && $examSection->speakingTask->is_published,
        };

        if (! $ok) {
            throw ValidationException::withMessages([
                'exam' => ["The {$examSection->skill} content for \"{$examSection->title}\" is unavailable right now."],
            ]);
        }
    }

    private function resolveDuration(FullMockExamSection $examSection): ?int
    {
        if ($examSection->duration_seconds !== null) {
            return (int) $examSection->duration_seconds;
        }

        return match ($examSection->skill) {
            FullMockExamSection::SKILL_LISTENING, FullMockExamSection::SKILL_READING => $examSection->mockTest?->duration_seconds !== null
                ? (int) $examSection->mockTest->duration_seconds
                : null,
            FullMockExamSection::SKILL_WRITING => $examSection->writingTask?->recommended_minutes
                ? (int) $examSection->writingTask->recommended_minutes * 60
                : null,
            default => null,
        };
    }

    private function secondsRemaining(FullMockSessionSection $section): ?int
    {
        if ($section->status !== FullMockSessionSection::STATUS_IN_PROGRESS || $section->must_submit_at === null) {
            return null;
        }

        return max(0, $section->must_submit_at->getTimestamp() - now()->getTimestamp());
    }

    // ---------------------------------------------------------------------
    // Payloads
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>|null  $navigation
     * @return array<string, mixed>
     */
    private function payload(FullMockSession $session, ?array $navigation = null): array
    {
        $session->refresh();
        $exam = FullMockExam::withTrashed()->find($session->full_mock_exam_id);

        $sections = FullMockSessionSection::query()
            ->where('full_mock_session_id', $session->id)
            ->with([
                'examSection.mockTest:id,slug,title,module',
                'examSection.writingTask:id,title',
                'examSection.speakingTask:id,title',
                'testAttempt.result',
                'writingSubmission.evaluation',
                'speakingRecording.evaluation',
            ])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $current = $sections->firstWhere('status', FullMockSessionSection::STATUS_IN_PROGRESS);

        $payload = [
            'session' => [
                'id' => $session->id,
                'uuid' => $session->uuid,
                'status' => $session->status,
                'current_position' => $session->current_position,
                'started_at' => $session->started_at?->toIso8601String(),
                'completed_at' => $session->completed_at?->toIso8601String(),
                'seconds_remaining' => $current !== null ? $this->secondsRemaining($current) : null,
                'server_now' => now()->toIso8601String(),
            ],
            'exam' => $exam === null ? null : [
                'id' => $exam->id,
                'title' => $exam->title,
                'slug' => $exam->slug,
                'exam_type' => $exam->exam_type,
                'instructions' => $exam->instructions,
                'is_free' => $exam->is_free,
                'allow_resume' => $exam->allow_resume,
            ],
            'sections' => $sections->map(fn (FullMockSessionSection $section) => $this->sectionPayload($section))->values()->all(),
            'scorecard' => $this->scorecard($sections),
        ];

        if ($navigation !== null) {
            $payload['navigation'] = $navigation;
        }

        return $payload;
    }

    /**
     * Per-skill bands for the session. Overall is null while any skill is pending evaluation.
     *
     * @param  Collection<int, FullMockSessionSection>  $sections
     * @return array<string, mixed>
     */
    private function scorecard(Collection $sections): array
    {
        $skills = [
            FullMockExamSection::SKILL_LISTENING => null,
            FullMockExamSection::SKILL_READING => null,
            FullMockExamSection::SKILL_WRITING => null,
            FullMockExamSection::SKILL_SPEAKING => null,
        ];

        foreach ($sections as $section) {
            $skills[$section->skill] = $this->skillScore($section);
        }

        $bands = [];
        $pending = false;
        foreach ($skills as $skill => $row) {
            if ($row === null) {
                // Skill not part of this exam — skip for overall.
                continue;
            }
            if (($row['status'] ?? null) !== 'scored' || $row['band_score'] === null) {
                $pending = true;
            } else {
                $bands[] = $row['band_score'];
            }
        }

        $presentSkills = array_values(array_filter($skills, fn ($row) => $row !== null));
        $canOverall = ! $pending
            && count($presentSkills) === 4
            && count($bands) === 4;

        return [
            'skills' => $skills,
            'overall_pending' => $pending || count($presentSkills) < 4,
            'overall_band' => $canOverall ? IeltsOverallBand::fromSkillBands($bands) : null,
            'overall_note' => $canOverall
                ? null
                : 'Overall IELTS band is hidden until Listening, Reading, Writing, and Speaking bands are all available.',
        ];
    }

    /**
     * @return array{status: string, band_score: float|null, raw_score?: float|null, max_score?: float|null}|null
     */
    private function skillScore(FullMockSessionSection $section): ?array
    {
        return match ($section->skill) {
            FullMockExamSection::SKILL_LISTENING, FullMockExamSection::SKILL_READING => $this->autoSkillScore($section),
            FullMockExamSection::SKILL_WRITING => $this->writingSkillScore($section),
            FullMockExamSection::SKILL_SPEAKING => $this->speakingSkillScore($section),
            default => null,
        };
    }

    /**
     * @return array{status: string, band_score: float|null, raw_score: float|null, max_score: float|null}
     */
    private function autoSkillScore(FullMockSessionSection $section): array
    {
        $result = $section->testAttempt?->result;
        if ($result !== null) {
            return [
                'status' => 'scored',
                'band_score' => $result->band_score !== null ? (float) $result->band_score : null,
                'raw_score' => (float) $result->raw_score,
                'max_score' => (float) $result->max_score,
            ];
        }

        return [
            'status' => in_array($section->status, [
                FullMockSessionSection::STATUS_COMPLETED,
                FullMockSessionSection::STATUS_EXPIRED,
            ], true) ? 'pending' : 'not_started',
            'band_score' => null,
            'raw_score' => null,
            'max_score' => null,
        ];
    }

    /**
     * @return array{status: string, band_score: float|null}
     */
    private function writingSkillScore(FullMockSessionSection $section): array
    {
        $evaluation = $section->writingSubmission?->evaluation;
        if ($evaluation !== null) {
            return [
                'status' => 'scored',
                'band_score' => (float) $evaluation->overall_band,
            ];
        }

        $submitted = $section->writingSubmission?->status === WritingSubmission::STATUS_SUBMITTED
            || in_array($section->status, [
                FullMockSessionSection::STATUS_COMPLETED,
                FullMockSessionSection::STATUS_EXPIRED,
            ], true);

        return [
            'status' => $submitted ? 'pending' : 'not_started',
            'band_score' => null,
        ];
    }

    /**
     * @return array{status: string, band_score: float|null}
     */
    private function speakingSkillScore(FullMockSessionSection $section): array
    {
        $evaluation = $section->speakingRecording?->evaluation;
        if ($evaluation !== null) {
            return [
                'status' => 'scored',
                'band_score' => (float) $evaluation->overall_band,
            ];
        }

        $submitted = $section->speakingRecording?->status === SpeakingRecording::STATUS_SUBMITTED
            || in_array($section->status, [
                FullMockSessionSection::STATUS_COMPLETED,
                FullMockSessionSection::STATUS_EXPIRED,
            ], true);

        return [
            'status' => $submitted ? 'pending' : 'not_started',
            'band_score' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionPayload(FullMockSessionSection $section): array
    {
        $examSection = $section->examSection;

        $source = match ($section->skill) {
            FullMockExamSection::SKILL_LISTENING, FullMockExamSection::SKILL_READING => [
                'mock_test_id' => $examSection?->mock_test_id,
                'slug' => $examSection?->mockTest?->slug,
                'title' => $examSection?->mockTest?->title,
            ],
            FullMockExamSection::SKILL_WRITING => [
                'writing_task_id' => $examSection?->writing_task_id,
                'title' => $examSection?->writingTask?->title,
            ],
            default => [
                'speaking_task_id' => $examSection?->speaking_task_id,
                'title' => $examSection?->speakingTask?->title,
            ],
        };

        return [
            'id' => $section->id,
            'skill' => $section->skill,
            'title' => $section->title,
            'position' => $section->position,
            'status' => $section->status,
            'duration_seconds' => $section->duration_seconds,
            'instructions' => $examSection?->instructions,
            'started_at' => $section->started_at?->toIso8601String(),
            'must_submit_at' => $section->must_submit_at?->toIso8601String(),
            'completed_at' => $section->completed_at?->toIso8601String(),
            'seconds_remaining' => $this->secondsRemaining($section),
            'test_attempt_id' => $section->test_attempt_id,
            'writing_submission_id' => $section->writing_submission_id,
            'speaking_recording_id' => $section->speaking_recording_id,
            'context_key' => $section->contextKey(),
            'source' => $source,
        ];
    }

    /**
     * Where the client should go next, plus the ids it needs. Route names match the SPA router.
     *
     * @return array<string, mixed>
     */
    private function navigation(FullMockSession $session, FullMockSessionSection $section): array
    {
        $examSection = $section->examSection;
        $contextKey = $section->contextKey();
        $query = ['context_key' => $contextKey, 'session' => $session->uuid];

        $navigation = [
            'session_uuid' => $session->uuid,
            'session_section_id' => $section->id,
            'skill' => $section->skill,
            'context_key' => $contextKey,
            'attempt_id' => null,
            'writing_task_id' => null,
            'speaking_task_id' => null,
            'writing_submission_id' => $section->writing_submission_id,
            'speaking_recording_id' => $section->speaking_recording_id,
            'must_submit_at' => $section->must_submit_at?->toIso8601String(),
            'seconds_remaining' => $this->secondsRemaining($section),
            'route' => null,
        ];

        switch ($section->skill) {
            case FullMockExamSection::SKILL_READING:
            case FullMockExamSection::SKILL_LISTENING:
                $navigation['attempt_id'] = $section->test_attempt_id;
                $navigation['route'] = [
                    'name' => $section->skill === FullMockExamSection::SKILL_READING ? 'reading-attempt' : 'listening-attempt',
                    'params' => ['attemptId' => $section->test_attempt_id],
                    'query' => $query,
                ];
                break;

            case FullMockExamSection::SKILL_WRITING:
                $navigation['writing_task_id'] = $examSection?->writing_task_id;
                $navigation['route'] = [
                    'name' => 'writing-editor',
                    'params' => ['id' => $examSection?->writing_task_id],
                    'query' => $query,
                ];
                break;

            default:
                $navigation['speaking_task_id'] = $examSection?->speaking_task_id;
                $navigation['route'] = [
                    'name' => 'speaking-task-detail',
                    'params' => ['id' => $examSection?->speaking_task_id],
                    'query' => $query,
                ];
                break;
        }

        return $navigation;
    }
}
