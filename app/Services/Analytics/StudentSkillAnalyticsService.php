<?php

namespace App\Services\Analytics;

use App\Models\FullMockSession;
use App\Models\MockTest;
use App\Models\SpeakingEvaluation;
use App\Models\SpeakingRecording;
use App\Models\TestAttempt;
use App\Models\User;
use App\Models\WritingEvaluation;
use App\Models\WritingSubmission;
use App\Support\ExamContext;
use App\Support\IeltsOverallBand;
use Illuminate\Support\Collection;

class StudentSkillAnalyticsService
{
    private const HISTORY_LIMIT = 12;

    /**
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $reading = $this->mockHistory($user->id, MockTest::MODULE_READING);
        $listening = $this->mockHistory($user->id, MockTest::MODULE_LISTENING);
        $writing = $this->writingHistory($user->id);
        $speaking = $this->speakingHistory($user->id);

        return [
            'latest_bands' => [
                'reading' => $reading[0]['band_score'] ?? null,
                'listening' => $listening[0]['band_score'] ?? null,
                'writing' => $writing[0]['overall_band'] ?? null,
                'speaking' => $speaking[0]['overall_band'] ?? null,
            ],
            'bands_over_time' => [
                'reading' => array_reverse($reading),
                'listening' => array_reverse($listening),
                'writing' => array_reverse($writing),
                'speaking' => array_reverse($speaking),
            ],
            'weak_areas' => [
                'reading' => $this->mockWeakSummary($reading),
                'listening' => $this->mockWeakSummary($listening),
                'writing' => $this->criteriaAverage($writing, [
                    'task_response',
                    'coherence',
                    'lexical',
                    'grammar',
                ]),
                'speaking' => $this->criteriaAverage($speaking, [
                    'fluency',
                    'lexical',
                    'grammar',
                    'pronunciation',
                ]),
            ],
            'pending_teacher_evaluations' => [
                'writing' => $this->pendingWriting($user->id),
                'speaking' => $this->pendingSpeaking($user->id),
            ],
            'full_mock_sessions' => $this->fullMockHistory($user->id),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mockHistory(int $userId, string $module): array
    {
        return TestAttempt::query()
            ->with(['mockTest:id,title,slug,module,exam_type', 'result'])
            ->where('user_id', $userId)
            ->where('status', TestAttempt::STATUS_SUBMITTED)
            ->whereHas('mockTest', fn ($query) => $query->where('module', $module))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(function (TestAttempt $attempt) {
                $band = $attempt->result?->band_score;

                return [
                    'attempt_id' => $attempt->id,
                    'title' => $attempt->mockTest?->title,
                    'slug' => $attempt->mockTest?->slug,
                    'exam_type' => $attempt->mockTest?->exam_type,
                    'context_key' => $attempt->context_key ?: 'practice',
                    'context_label' => ExamContext::label((string) ($attempt->context_key ?: 'practice')),
                    'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                    'raw_score' => $attempt->result?->raw_score,
                    'max_score' => $attempt->result?->max_score,
                    'band_score' => $band !== null ? (float) $band : null,
                    'accuracy' => $this->accuracy($attempt->result?->raw_score, $attempt->result?->max_score),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function writingHistory(int $userId): array
    {
        return WritingEvaluation::query()
            ->with(['submission.task:id,title,task_type,exam_type'])
            ->whereHas('submission', fn ($query) => $query->where('user_id', $userId))
            ->orderByDesc('evaluated_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(function (WritingEvaluation $evaluation) {
                $submission = $evaluation->submission;
                $contextKey = (string) ($submission?->context_key ?: 'practice');

                return [
                    'evaluation_id' => $evaluation->id,
                    'submission_id' => $submission?->id,
                    'task_id' => $submission?->writing_task_id,
                    'title' => $submission?->task?->title,
                    'task_type' => $submission?->task?->task_type,
                    'context_key' => $contextKey,
                    'context_label' => ExamContext::label($contextKey),
                    'evaluated_at' => $evaluation->evaluated_at?->toIso8601String(),
                    'overall_band' => (float) $evaluation->overall_band,
                    'task_response' => (float) $evaluation->task_response,
                    'coherence' => (float) $evaluation->coherence,
                    'lexical' => (float) $evaluation->lexical,
                    'grammar' => (float) $evaluation->grammar,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function speakingHistory(int $userId): array
    {
        return SpeakingEvaluation::query()
            ->with(['recording.task:id,title,part'])
            ->whereHas('recording', fn ($query) => $query->where('user_id', $userId))
            ->orderByDesc('evaluated_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(function (SpeakingEvaluation $evaluation) {
                $recording = $evaluation->recording;
                $contextKey = (string) ($recording?->context_key ?: 'practice');

                return [
                    'evaluation_id' => $evaluation->id,
                    'recording_id' => $recording?->id,
                    'task_id' => $recording?->speaking_task_id,
                    'title' => $recording?->task?->title,
                    'part' => $recording?->task?->part,
                    'context_key' => $contextKey,
                    'context_label' => ExamContext::label($contextKey),
                    'evaluated_at' => $evaluation->evaluated_at?->toIso8601String(),
                    'overall_band' => (float) $evaluation->overall_band,
                    'fluency' => (float) $evaluation->fluency,
                    'lexical' => (float) $evaluation->lexical,
                    'grammar' => (float) $evaluation->grammar,
                    'pronunciation' => (float) $evaluation->pronunciation,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function mockWeakSummary(array $rows): array
    {
        $withBand = array_values(array_filter($rows, fn ($row) => $row['band_score'] !== null));
        $withAccuracy = array_values(array_filter($rows, fn ($row) => $row['accuracy'] !== null));

        $avgBand = $withBand === []
            ? null
            : round(array_sum(array_column($withBand, 'band_score')) / count($withBand), 1);

        $avgAccuracy = $withAccuracy === []
            ? null
            : round(array_sum(array_column($withAccuracy, 'accuracy')) / count($withAccuracy), 1);

        $lowest = null;
        foreach ($withBand as $row) {
            if ($lowest === null || $row['band_score'] < $lowest['band_score']) {
                $lowest = $row;
            }
        }

        return [
            'sample_size' => count($rows),
            'average_band' => $avgBand,
            'average_accuracy_percent' => $avgAccuracy,
            'lowest' => $lowest ? [
                'attempt_id' => $lowest['attempt_id'],
                'title' => $lowest['title'],
                'band_score' => $lowest['band_score'],
                'accuracy' => $lowest['accuracy'],
                'submitted_at' => $lowest['submitted_at'],
            ] : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function criteriaAverage(array $rows, array $keys): array
    {
        $averages = [];
        foreach ($keys as $key) {
            $values = array_values(array_filter(
                array_map(fn ($row) => isset($row[$key]) ? (float) $row[$key] : null, $rows),
                fn ($value) => $value !== null,
            ));
            $averages[$key] = $values === []
                ? null
                : round(array_sum($values) / count($values), 1);
        }

        $weakestKey = null;
        $weakestValue = null;
        foreach ($averages as $key => $value) {
            if ($value === null) {
                continue;
            }
            if ($weakestValue === null || $value < $weakestValue) {
                $weakestKey = $key;
                $weakestValue = $value;
            }
        }

        return [
            'sample_size' => count($rows),
            'averages' => $averages,
            'weakest_criterion' => $weakestKey,
            'weakest_average' => $weakestValue,
        ];
    }

    /**
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function pendingWriting(int $userId): array
    {
        $query = WritingSubmission::query()
            ->where('user_id', $userId)
            ->where('status', WritingSubmission::STATUS_SUBMITTED)
            ->whereDoesntHave('evaluation');

        $items = (clone $query)
            ->with('task:id,title')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (WritingSubmission $submission) => [
                'id' => $submission->id,
                'task_id' => $submission->writing_task_id,
                'title' => $submission->task?->title,
                'context_key' => $submission->context_key ?: 'practice',
                'submitted_at' => $submission->submitted_at?->toIso8601String(),
            ])
            ->all();

        return ['total' => $query->count(), 'items' => $items];
    }

    /**
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function pendingSpeaking(int $userId): array
    {
        $query = SpeakingRecording::query()
            ->where('user_id', $userId)
            ->where('status', SpeakingRecording::STATUS_SUBMITTED)
            ->whereDoesntHave('evaluation');

        $items = (clone $query)
            ->with('task:id,title')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (SpeakingRecording $recording) => [
                'id' => $recording->id,
                'task_id' => $recording->speaking_task_id,
                'title' => $recording->task?->title,
                'context_key' => $recording->context_key ?: 'practice',
                'submitted_at' => $recording->submitted_at?->toIso8601String(),
            ])
            ->all();

        return ['total' => $query->count(), 'items' => $items];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fullMockHistory(int $userId): array
    {
        return FullMockSession::query()
            ->with([
                'exam:id,title,slug,exam_type',
                'sections' => fn ($query) => $query->orderBy('position')->orderBy('id'),
            ])
            ->where('user_id', $userId)
            ->whereIn('status', [FullMockSession::STATUS_COMPLETED, FullMockSession::STATUS_ABANDONED])
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(function (FullMockSession $session) {
                $bands = $this->fullMockSkillBands($session->sections);

                return [
                    'uuid' => $session->uuid,
                    'exam_title' => $session->exam?->title,
                    'exam_type' => $session->exam?->exam_type,
                    'status' => $session->status,
                    'completed_at' => $session->completed_at?->toIso8601String(),
                    'skills' => $bands,
                    'overall_pending' => count(array_filter($bands, fn ($band) => $band === null)) > 0,
                    'overall_band' => $this->overallFromBands($bands),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Lightweight band extraction without re-running the full scorecard service.
     *
     * @param  Collection<int, \App\Models\FullMockSessionSection>  $sections
     * @return array{listening: float|null, reading: float|null, writing: float|null, speaking: float|null}
     */
    private function fullMockSkillBands(Collection $sections): array
    {
        $bands = [
            'listening' => null,
            'reading' => null,
            'writing' => null,
            'speaking' => null,
        ];

        foreach ($sections as $section) {
            $skill = $section->skill;
            if (! array_key_exists($skill, $bands)) {
                continue;
            }

            if (in_array($skill, ['listening', 'reading'], true) && $section->test_attempt_id) {
                $attempt = TestAttempt::query()
                    ->with('result:id,test_attempt_id,band_score')
                    ->whereKey($section->test_attempt_id)
                    ->first();
                if ($attempt?->result?->band_score !== null) {
                    $bands[$skill] = (float) $attempt->result->band_score;
                }
            }

            if ($skill === 'writing' && $section->writing_submission_id) {
                $evaluation = WritingEvaluation::query()
                    ->where('writing_submission_id', $section->writing_submission_id)
                    ->first();
                if ($evaluation) {
                    $bands[$skill] = (float) $evaluation->overall_band;
                }
            }

            if ($skill === 'speaking' && $section->speaking_recording_id) {
                $evaluation = SpeakingEvaluation::query()
                    ->where('speaking_recording_id', $section->speaking_recording_id)
                    ->first();
                if ($evaluation) {
                    $bands[$skill] = (float) $evaluation->overall_band;
                }
            }
        }

        return $bands;
    }

    /**
     * @param  array{listening: float|null, reading: float|null, writing: float|null, speaking: float|null}  $bands
     */
    private function overallFromBands(array $bands): ?float
    {
        $values = array_values(array_filter($bands, fn ($band) => $band !== null));
        if (count($values) < 4) {
            return null;
        }

        return (float) IeltsOverallBand::fromSkillBands($values);
    }

    private function accuracy(mixed $raw, mixed $max): ?float
    {
        $rawScore = $raw === null ? null : (float) $raw;
        $maxScore = $max === null ? null : (float) $max;
        if ($rawScore === null || $maxScore === null || $maxScore <= 0) {
            return null;
        }

        return round(($rawScore / $maxScore) * 100, 1);
    }
}
