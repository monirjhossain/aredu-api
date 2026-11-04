<?php

namespace Database\Seeders;

use App\Models\MockTest;
use App\Models\SpeakingEvaluation;
use App\Models\SpeakingRecording;
use App\Models\SpeakingTask;
use App\Models\TestAttempt;
use App\Models\TestResult;
use App\Models\User;
use App\Models\WritingEvaluation;
use App\Models\WritingSubmission;
use App\Models\WritingTask;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DemoResultsSeeder extends Seeder
{
    public function run(): void
    {
        $students = User::query()
            ->whereIn('email', ['student1@areducation.test', 'student2@areducation.test'])
            ->get();
        $teacher = User::query()->where('email', 'teacher1@areducation.test')->first();

        if ($students->isEmpty()) {
            $this->command?->warn('DemoResultsSeeder skipped: demo students missing. Run DemoUserSeeder first.');

            return;
        }

        $created = 0;

        $readingTests = MockTest::query()
            ->where('module', MockTest::MODULE_READING)
            ->where('is_published', true)
            ->orderBy('id')
            ->limit(12)
            ->get();

        $listeningTests = MockTest::query()
            ->where('module', MockTest::MODULE_LISTENING)
            ->where('is_published', true)
            ->orderBy('id')
            ->limit(12)
            ->get();

        foreach ($students as $studentIndex => $student) {
            foreach ($readingTests->take(6) as $offset => $test) {
                if ($this->seedAttemptResult($student, $test, 2 + (($offset + $studentIndex) % 2), 3, 5.0 + ($offset % 4) * 0.5)) {
                    $created++;
                }
            }
            foreach ($listeningTests->take(6) as $offset => $test) {
                if ($this->seedAttemptResult($student, $test, 4 + ($offset % 3), 8, 5.5 + ($offset % 4) * 0.5)) {
                    $created++;
                }
            }
        }

        $writingTasks = WritingTask::query()->where('is_published', true)->orderBy('id')->limit(12)->get();
        foreach ($students as $studentIndex => $student) {
            foreach ($writingTasks->take(6) as $offset => $task) {
                if ($this->seedWritingResult($student, $task, $teacher, 6.0 + (($offset + $studentIndex) % 5) * 0.5)) {
                    $created++;
                }
            }
        }

        $speakingTasks = SpeakingTask::query()->where('is_published', true)->orderBy('id')->limit(12)->get();
        foreach ($students as $studentIndex => $student) {
            foreach ($speakingTasks->take(6) as $offset => $task) {
                if ($this->seedSpeakingResult($student, $task, $teacher, 5.5 + (($offset + $studentIndex) % 5) * 0.5)) {
                    $created++;
                }
            }
        }

        $this->command?->info("Demo results ready: {$created} scored items across reading/listening/writing/speaking.");
    }

    private function seedAttemptResult(User $student, MockTest $test, float $raw, float $max, float $band): bool
    {
        $started = now()->subDays(random_int(1, 20))->subMinutes(random_int(10, 120));
        $submitted = (clone $started)->addMinutes(random_int(20, 55));

        $attempt = TestAttempt::query()->updateOrCreate(
            [
                'mock_test_id' => $test->id,
                'user_id' => $student->id,
                'status' => TestAttempt::STATUS_SUBMITTED,
            ],
            [
                'started_at' => $started,
                'must_submit_at' => (clone $started)->addSeconds($test->duration_seconds ?: 3600),
                'submitted_at' => $submitted,
            ],
        );

        TestResult::query()->updateOrCreate(
            ['test_attempt_id' => $attempt->id],
            [
                'raw_score' => $raw,
                'max_score' => $max,
                'band_score' => $band,
            ],
        );

        return true;
    }

    private function seedWritingResult(User $student, WritingTask $task, ?User $teacher, float $band): bool
    {
        $body = 'This is a demo writing answer for '.$task->title.'. '
            .'Students should develop ideas clearly with supporting examples and accurate grammar. '
            .'The response covers the task requirements in enough detail for practice scoring.';

        $submission = WritingSubmission::query()->updateOrCreate(
            [
                'writing_task_id' => $task->id,
                'user_id' => $student->id,
            ],
            [
                'body' => $body,
                'word_count' => str_word_count($body),
                'status' => WritingSubmission::STATUS_SUBMITTED,
                'submitted_at' => now()->subDays(random_int(1, 14)),
            ],
        );

        if ($teacher) {
            WritingEvaluation::query()->updateOrCreate(
                ['writing_submission_id' => $submission->id],
                [
                    'teacher_id' => $teacher->id,
                    'task_response' => $band,
                    'coherence' => max(4.0, $band - 0.5),
                    'lexical' => $band,
                    'grammar' => max(4.0, $band - 0.5),
                    'overall_band' => $band,
                    'feedback' => 'Solid practice response. Develop examples further and check article use.',
                    'evaluated_at' => now()->subDays(random_int(0, 10)),
                ],
            );
        }

        return true;
    }

    private function seedSpeakingResult(User $student, SpeakingTask $task, ?User $teacher, float $band): bool
    {
        $path = "speaking-recordings/demo/{$student->id}/task-{$task->id}.webm";
        Storage::disk('local')->put($path, 'demo-speaking-audio');

        $recording = SpeakingRecording::query()->updateOrCreate(
            [
                'speaking_task_id' => $task->id,
                'user_id' => $student->id,
            ],
            [
                'disk' => 'local',
                'path' => $path,
                'mime' => 'audio/webm',
                'size_bytes' => 2048,
                'duration_seconds' => max(20, (int) $task->response_seconds - 10),
                'status' => SpeakingRecording::STATUS_SUBMITTED,
                'submitted_at' => now()->subDays(random_int(1, 14)),
            ],
        );

        if ($teacher) {
            SpeakingEvaluation::query()->updateOrCreate(
                ['speaking_recording_id' => $recording->id],
                [
                    'teacher_id' => $teacher->id,
                    'fluency' => $band,
                    'lexical' => max(4.0, $band - 0.5),
                    'grammar' => $band,
                    'pronunciation' => max(4.0, $band - 0.5),
                    'overall_band' => $band,
                    'feedback' => 'Clear delivery. Extend answers and use more precise vocabulary.',
                    'evaluated_at' => now()->subDays(random_int(0, 10)),
                ],
            );
        }

        return true;
    }
}
