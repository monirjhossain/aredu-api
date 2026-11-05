<?php

namespace Tests\Feature;

use App\Models\MockTest;
use App\Models\Role;
use App\Models\SpeakingEvaluation;
use App\Models\SpeakingRecording;
use App\Models\SpeakingTask;
use App\Models\TestAttempt;
use App\Models\TestResult;
use App\Models\User;
use App\Models\WritingEvaluation;
use App\Models\WritingSubmission;
use App\Models\WritingTask;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class StudentSkillAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_student_receives_skill_history_and_weak_area_summary(): void
    {
        $student = $this->student();
        $teacher = $this->userWithRole('teacher');

        $reading = MockTest::query()->create([
            'module' => MockTest::MODULE_READING,
            'exam_type' => 'academic',
            'title' => 'Reading Sample',
            'slug' => 'reading-sample-analytics',
            'duration_seconds' => 3600,
            'is_published' => true,
            'is_free_sample' => true,
        ]);
        $attempt = TestAttempt::query()->create([
            'user_id' => $student->id,
            'mock_test_id' => $reading->id,
            'status' => TestAttempt::STATUS_SUBMITTED,
            'context_key' => 'practice',
            'started_at' => now()->subHour(),
            'must_submit_at' => now()->subMinutes(20),
            'submitted_at' => now()->subMinutes(30),
        ]);
        TestResult::query()->create([
            'test_attempt_id' => $attempt->id,
            'raw_score' => 20,
            'max_score' => 40,
            'band_score' => 5.5,
            'breakdown' => [],
        ]);

        $writingTask = WritingTask::query()->create([
            'exam_type' => 'academic',
            'task_type' => WritingTask::TYPE_TASK_2,
            'title' => 'Essay Analytics',
            'prompt' => 'Discuss.',
            'min_words' => 50,
            'is_published' => true,
        ]);
        $submission = WritingSubmission::query()->create([
            'writing_task_id' => $writingTask->id,
            'user_id' => $student->id,
            'body' => implode(' ', array_fill(0, 55, 'word')),
            'word_count' => 55,
            'status' => WritingSubmission::STATUS_SUBMITTED,
            'context_key' => 'practice',
            'submitted_at' => now()->subDay(),
        ]);
        WritingEvaluation::query()->create([
            'writing_submission_id' => $submission->id,
            'teacher_id' => $teacher->id,
            'task_response' => 6.0,
            'coherence' => 6.0,
            'lexical' => 5.0,
            'grammar' => 5.5,
            'overall_band' => 5.5,
            'feedback' => 'Work on vocabulary.',
            'evaluated_at' => now()->subHours(2),
        ]);

        $speakingTask = SpeakingTask::query()->create([
            'part' => SpeakingTask::PART_2,
            'title' => 'Speaking Analytics',
            'instructions' => 'Speak',
            'preparation_seconds' => 60,
            'response_seconds' => 120,
            'is_published' => true,
        ]);
        $recording = SpeakingRecording::query()->create([
            'speaking_task_id' => $speakingTask->id,
            'user_id' => $student->id,
            'disk' => 'local',
            'path' => 'speaking-recordings/analytics.webm',
            'mime' => 'audio/webm',
            'size_bytes' => 100,
            'status' => SpeakingRecording::STATUS_SUBMITTED,
            'context_key' => 'practice',
            'submitted_at' => now()->subHours(5),
        ]);
        SpeakingEvaluation::query()->create([
            'speaking_recording_id' => $recording->id,
            'teacher_id' => $teacher->id,
            'fluency' => 6.0,
            'lexical' => 6.0,
            'grammar' => 5.5,
            'pronunciation' => 5.0,
            'overall_band' => 5.5,
            'feedback' => 'Pronunciation focus.',
            'evaluated_at' => now()->subHour(),
        ]);

        Auth::forgetGuards();
        $response = $this->withToken($student->createToken('api')->plainTextToken)
            ->getJson('/api/v1/student/skill-analytics')
            ->assertOk()
            ->assertJsonPath('data.latest_bands.reading', 5.5)
            ->assertJsonPath('data.latest_bands.writing', 5.5)
            ->assertJsonPath('data.latest_bands.speaking', 5.5)
            ->assertJsonPath('data.weak_areas.writing.weakest_criterion', 'lexical')
            ->assertJsonPath('data.weak_areas.speaking.weakest_criterion', 'pronunciation');

        $this->assertNotEmpty($response->json('data.bands_over_time.reading'));
        $this->assertEqualsWithDelta(50.0, (float) $response->json('data.bands_over_time.reading.0.accuracy'), 0.01);
    }

    public function test_guest_cannot_access_skill_analytics(): void
    {
        $this->getJson('/api/v1/student/skill-analytics')->assertUnauthorized();
    }

    private function student(): User
    {
        return $this->userWithRole('student');
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->value('id'));
        Auth::forgetGuards();

        return $user;
    }
}
