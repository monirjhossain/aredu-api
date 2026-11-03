<?php

namespace App\Services\MockTest;

use App\Models\BandScoreConversion;
use App\Models\MockTest;
use App\Models\Question;
use App\Models\QuestionGroup;
use App\Models\TestAttempt;
use App\Models\TestAttemptAnswer;
use App\Models\TestResult;
use App\Models\User;
use App\Notifications\MockTestResultAvailableNotification;
use App\Services\Settings\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReadingAttemptService
{
    public function __construct(private readonly SettingService $settings) {}

    /**
     * @param  array{search?: string|null, exam_type?: string|null}  $filters
     */
    public function paginatePublished(array $filters = []): LengthAwarePaginator
    {
        $search = $this->term($filters['search'] ?? null);

        return MockTest::query()
            ->where('module', MockTest::MODULE_READING)
            ->where('is_published', true)
            ->when($search !== null, function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', $search)->orWhere('slug', 'like', $search);
                });
            })
            ->when(($filters['exam_type'] ?? null) !== null && $filters['exam_type'] !== '', function ($query) use ($filters) {
                $query->where('exam_type', $filters['exam_type']);
            })
            ->orderBy('title')
            ->orderBy('id')
            ->paginate(12);
    }

    public function publishedOverview(string $slug): MockTest
    {
        $test = MockTest::query()
            ->where('module', MockTest::MODULE_READING)
            ->where('is_published', true)
            ->where('slug', $slug)
            ->first();

        if ($test === null) {
            throw (new ModelNotFoundException)->setModel(MockTest::class, [$slug]);
        }

        return $test;
    }

    /**
     * @return array<string, mixed>
     */
    public function startOrResume(User $user, string $slug): array
    {
        $test = $this->publishedOverview($slug);

        return DB::transaction(function () use ($user, $test) {
            $existing = TestAttempt::query()
                ->where('user_id', $user->id)
                ->where('mock_test_id', $test->id)
                ->where('status', TestAttempt::STATUS_IN_PROGRESS)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! $this->isExpired($existing)) {
                    return $this->payload($existing, false);
                }

                $this->finalizeAttempt($existing);
            }

            $startedAt = now();
            $attempt = TestAttempt::query()->create([
                'mock_test_id' => $test->id,
                'user_id' => $user->id,
                'started_at' => $startedAt,
                'must_submit_at' => $startedAt->copy()->addSeconds((int) $test->duration_seconds),
                'status' => TestAttempt::STATUS_IN_PROGRESS,
            ]);

            return $this->payload($attempt, false);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function showAttempt(User $user, TestAttempt $attempt): array
    {
        $attempt = $this->ownedReadingAttempt($user, $attempt);

        if ($attempt->status === TestAttempt::STATUS_IN_PROGRESS && $this->isExpired($attempt)) {
            $attempt = $this->finalizeAttempt($attempt);
        }

        return $this->payload($attempt, $attempt->status === TestAttempt::STATUS_SUBMITTED);
    }

    /**
     * @param  list<array{question_id: int, question_option_id?: int|null, answer_text?: string|null}>  $answers
     * @return array<string, mixed>
     */
    public function saveAnswers(User $user, TestAttempt $attempt, array $answers): array
    {
        $attempt = $this->ownedReadingAttempt($user, $attempt);

        if ($attempt->status !== TestAttempt::STATUS_IN_PROGRESS) {
            throw ValidationException::withMessages([
                'attempt' => ['This attempt is already submitted.'],
            ]);
        }

        if ($this->isExpired($attempt)) {
            $attempt = $this->finalizeAttempt($attempt);

            throw ValidationException::withMessages([
                'attempt' => ['Time expired. The attempt was submitted automatically.'],
            ]);
        }

        $questionIds = $this->questionIdsForTest((int) $attempt->mock_test_id);
        $optionIdsByGroup = $this->optionIdsByGroupForTest((int) $attempt->mock_test_id);
        $groupByQuestion = $this->groupMetaByQuestion((int) $attempt->mock_test_id);

        DB::transaction(function () use ($attempt, $answers, $questionIds, $optionIdsByGroup, $groupByQuestion) {
            $locked = TestAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== TestAttempt::STATUS_IN_PROGRESS) {
                throw ValidationException::withMessages([
                    'attempt' => ['This attempt is already submitted.'],
                ]);
            }

            foreach ($answers as $index => $row) {
                $questionId = (int) ($row['question_id'] ?? 0);
                if (! isset($questionIds[$questionId])) {
                    throw ValidationException::withMessages([
                        "answers.$index.question_id" => ['Unknown question for this test.'],
                    ]);
                }

                $meta = $groupByQuestion[$questionId];
                $optionId = array_key_exists('question_option_id', $row) ? $row['question_option_id'] : null;
                $answerText = array_key_exists('answer_text', $row) ? $row['answer_text'] : null;

                if ($meta['uses_options']) {
                    if ($optionId !== null && ! isset($optionIdsByGroup[$meta['group_id']][(int) $optionId])) {
                        throw ValidationException::withMessages([
                            "answers.$index.question_option_id" => ['Option does not belong to this question group.'],
                        ]);
                    }
                    $answerText = null;
                } else {
                    $optionId = null;
                    if (is_string($answerText)) {
                        $answerText = trim($answerText);
                        if ($answerText === '') {
                            $answerText = null;
                        }
                    }
                }

                TestAttemptAnswer::query()->updateOrCreate(
                    [
                        'test_attempt_id' => $attempt->id,
                        'question_id' => $questionId,
                    ],
                    [
                        'question_option_id' => $optionId,
                        'answer_text' => $answerText,
                    ],
                );
            }
        });

        return $this->payload($attempt->fresh(), false);
    }

    /**
     * @return array<string, mixed>
     */
    public function submit(User $user, TestAttempt $attempt): array
    {
        $attempt = $this->ownedReadingAttempt($user, $attempt);

        if ($attempt->status === TestAttempt::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'attempt' => ['This attempt was already submitted.'],
            ]);
        }

        $attempt = $this->finalizeAttempt($attempt);

        return $this->payload($attempt, true);
    }

    public function paginateHistory(User $user): LengthAwarePaginator
    {
        return TestAttempt::query()
            ->with(['mockTest:id,title,slug,exam_type,module', 'result'])
            ->where('user_id', $user->id)
            ->whereHas('mockTest', fn ($query) => $query->where('module', MockTest::MODULE_READING))
            ->where('status', TestAttempt::STATUS_SUBMITTED)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(12);
    }

    private function finalizeAttempt(TestAttempt $attempt): TestAttempt
    {
        return DB::transaction(function () use ($attempt) {
            $attempt = TestAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($attempt->status === TestAttempt::STATUS_SUBMITTED) {
                return $attempt->load(['mockTest', 'answers', 'result']);
            }

            $attempt->status = TestAttempt::STATUS_SUBMITTED;
            $attempt->submitted_at = now();
            $attempt->save();

            $this->scoreAttempt($attempt);

            $fresh = $attempt->fresh()->load(['mockTest', 'answers', 'result', 'user']);
            $notify = $this->settings->notificationsEnabled('mock_result');
            DB::afterCommit(function () use ($fresh, $notify) {
                if ($notify && $fresh->user && $fresh->mockTest) {
                    $fresh->user->notify(new MockTestResultAvailableNotification($fresh, $fresh->mockTest));
                }
            });

            return $fresh;
        });
    }

    private function scoreAttempt(TestAttempt $attempt): void
    {
        $attempt->loadMissing(['mockTest', 'answers']);
        $questions = Question::query()
            ->with(['acceptedAnswers', 'group'])
            ->whereHas('group.section', fn ($query) => $query->where('mock_test_id', $attempt->mock_test_id))
            ->get();

        $answers = $attempt->answers->keyBy('question_id');
        $raw = 0.0;
        $max = 0.0;

        foreach ($questions as $question) {
            $marks = (float) $question->marks;
            $max += $marks;
            $answer = $answers->get($question->id);
            if ($answer === null) {
                continue;
            }

            $group = $question->group;
            if ($group === null) {
                continue;
            }

            if ($group->usesOptions()) {
                if ($question->correct_option_id !== null
                    && (int) $answer->question_option_id === (int) $question->correct_option_id) {
                    $raw += $marks;
                }
            } elseif ($group->usesAcceptedAnswers()) {
                $given = $this->normalizeText($answer->answer_text);
                if ($given === null) {
                    continue;
                }
                foreach ($question->acceptedAnswers as $accepted) {
                    if ($this->normalizeText($accepted->answer_text) === $given) {
                        $raw += $marks;
                        break;
                    }
                }
            }
        }

        $rawRounded = (int) round($raw);
        $band = BandScoreConversion::query()
            ->where('module', MockTest::MODULE_READING)
            ->where('exam_type', $attempt->mockTest->exam_type)
            ->where('raw_score', $rawRounded)
            ->value('band_score');

        TestResult::query()->updateOrCreate(
            ['test_attempt_id' => $attempt->id],
            [
                'raw_score' => number_format($raw, 2, '.', ''),
                'max_score' => number_format($max, 2, '.', ''),
                'band_score' => $band,
            ],
        );
    }

    private function ownedReadingAttempt(User $user, TestAttempt $attempt): TestAttempt
    {
        $attempt->loadMissing('mockTest');

        if ((int) $attempt->user_id !== (int) $user->id) {
            throw new AuthorizationException('You can only access your own attempts.');
        }

        if ($attempt->mockTest === null || $attempt->mockTest->module !== MockTest::MODULE_READING) {
            throw (new ModelNotFoundException)->setModel(TestAttempt::class, [$attempt->id]);
        }

        return $attempt;
    }

    private function isExpired(TestAttempt $attempt): bool
    {
        return $attempt->must_submit_at !== null && $attempt->must_submit_at->lte(now());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(TestAttempt $attempt, bool $includeResult): array
    {
        $attempt->loadMissing([
            'mockTest',
            'answers',
            'result',
            'mockTest.sections.passages',
            'mockTest.sections.groups.options',
            'mockTest.sections.groups.questions.acceptedAnswers',
        ]);

        $test = $attempt->mockTest;
        $answers = $attempt->answers->keyBy('question_id');

        return [
            'attempt' => [
                'id' => $attempt->id,
                'status' => $attempt->status,
                'started_at' => $attempt->started_at?->toIso8601String(),
                'must_submit_at' => $attempt->must_submit_at?->toIso8601String(),
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                'seconds_remaining' => $attempt->status === TestAttempt::STATUS_IN_PROGRESS && $attempt->must_submit_at
                    ? max(0, $attempt->must_submit_at->getTimestamp() - now()->getTimestamp())
                    : 0,
                'server_now' => now()->toIso8601String(),
            ],
            'test' => [
                'id' => $test->id,
                'title' => $test->title,
                'slug' => $test->slug,
                'exam_type' => $test->exam_type,
                'instructions' => $test->instructions,
                'duration_seconds' => $test->duration_seconds,
                'sections' => $test->sections->map(function ($section) use ($answers, $includeResult) {
                    return [
                        'id' => $section->id,
                        'title' => $section->title,
                        'position' => $section->position,
                        'passages' => $section->passages->map(fn ($passage) => [
                            'id' => $passage->id,
                            'title' => $passage->title,
                            'body' => $passage->body,
                            'position' => $passage->position,
                        ])->values(),
                        'groups' => $section->groups->map(function ($group) use ($answers, $includeResult) {
                            return [
                                'id' => $group->id,
                                'type' => $group->type,
                                'instructions' => $group->instructions,
                                'position' => $group->position,
                                'question_passage_id' => $group->question_passage_id,
                                'options' => $group->options->map(fn ($option) => [
                                    'id' => $option->id,
                                    'label' => $option->label,
                                    'content' => $option->content,
                                    'position' => $option->position,
                                ])->values(),
                                'questions' => $group->questions->map(function ($question) use ($answers, $includeResult, $group) {
                                    $saved = $answers->get($question->id);
                                    $row = [
                                        'id' => $question->id,
                                        'prompt' => $question->prompt,
                                        'position' => $question->position,
                                        'marks' => $question->marks,
                                        'answer' => $saved ? [
                                            'question_option_id' => $saved->question_option_id,
                                            'answer_text' => $saved->answer_text,
                                        ] : null,
                                    ];

                                    if ($includeResult) {
                                        if ($group->usesOptions()) {
                                            $row['correct_option_id'] = $question->correct_option_id;
                                        }
                                        if ($group->usesAcceptedAnswers()) {
                                            $question->loadMissing('acceptedAnswers');
                                            $row['accepted_answers'] = $question->acceptedAnswers
                                                ->pluck('answer_text')
                                                ->values()
                                                ->all();
                                        }
                                    }

                                    return $row;
                                })->values(),
                            ];
                        })->values(),
                    ];
                })->values(),
            ],
            'result' => $includeResult && $attempt->result ? [
                'raw_score' => $attempt->result->raw_score,
                'max_score' => $attempt->result->max_score,
                'band_score' => $attempt->result->band_score,
            ] : null,
        ];
    }

    /**
     * @return array<int, true>
     */
    private function questionIdsForTest(int $testId): array
    {
        return Question::query()
            ->whereHas('group.section', fn ($query) => $query->where('mock_test_id', $testId))
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * @return array<int, array<int, true>>
     */
    private function optionIdsByGroupForTest(int $testId): array
    {
        $rows = DB::table('question_options')
            ->join('question_groups', 'question_groups.id', '=', 'question_options.question_group_id')
            ->join('mock_test_sections', 'mock_test_sections.id', '=', 'question_groups.mock_test_section_id')
            ->where('mock_test_sections.mock_test_id', $testId)
            ->get(['question_options.id', 'question_options.question_group_id']);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->question_group_id][(int) $row->id] = true;
        }

        return $map;
    }

    /**
     * @return array<int, array{group_id: int, uses_options: bool}>
     */
    private function groupMetaByQuestion(int $testId): array
    {
        $questions = Question::query()
            ->with('group:id,type')
            ->whereHas('group.section', fn ($query) => $query->where('mock_test_id', $testId))
            ->get(['id', 'question_group_id']);

        $map = [];
        foreach ($questions as $question) {
            $map[(int) $question->id] = [
                'group_id' => (int) $question->question_group_id,
                'uses_options' => $question->group?->usesOptions() ?? false,
            ];
        }

        return $map;
    }

    private function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if ($value === '') {
            return null;
        }

        return mb_strtolower($value);
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
