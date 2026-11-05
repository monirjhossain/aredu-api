<?php

namespace Tests\Feature;

use App\Models\FullMockExam;
use App\Models\FullMockExamSection;
use App\Models\FullMockSession;
use App\Models\FullMockSessionSection;
use App\Models\Role;
use App\Models\SpeakingRecording;
use App\Models\SpeakingTask;
use App\Models\User;
use App\Models\WritingSubmission;
use App\Models\WritingTask;
use App\Notifications\WritingEvaluationCompletedNotification;
use App\Services\Writing\WritingEvaluationService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeacherEvaluationContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_teacher_writing_queue_filters_practice_vs_full_mock_and_exposes_context(): void
    {
        $task = WritingTask::query()->create([
            'exam_type' => 'academic',
            'task_type' => WritingTask::TYPE_TASK_2,
            'title' => 'Context filter essay',
            'prompt' => 'Discuss both views.',
            'recommended_minutes' => 40,
            'min_words' => 50,
            'is_published' => true,
        ]);

        $student = $this->userWithRole('student');
        $teacher = $this->userWithRole('teacher');
        $body = implode(' ', array_fill(0, 55, 'word'));

        $practice = WritingSubmission::query()->create([
            'writing_task_id' => $task->id,
            'user_id' => $student->id,
            'body' => $body,
            'word_count' => 55,
            'status' => WritingSubmission::STATUS_SUBMITTED,
            'context_key' => WritingSubmission::CONTEXT_PRACTICE,
            'submitted_at' => now()->subMinute(),
        ]);

        $sessionSection = $this->makeWritingSessionSection($student, $task);
        $fullMockKey = $sessionSection->contextKey();

        $fullMock = WritingSubmission::query()->create([
            'writing_task_id' => $task->id,
            'user_id' => $student->id,
            'body' => $body,
            'word_count' => 55,
            'status' => WritingSubmission::STATUS_SUBMITTED,
            'context_key' => $fullMockKey,
            'submitted_at' => now(),
        ]);

        Auth::forgetGuards();
        $token = $teacher->createToken('api')->plainTextToken;

        $all = $this->withToken($token)
            ->getJson('/api/v1/teacher/writing-submissions?evaluation_status=pending')
            ->assertOk();
        $this->assertSame(2, $all->json('meta.total'));

        $practiceOnly = $this->withToken($token)
            ->getJson('/api/v1/teacher/writing-submissions?evaluation_status=pending&context=practice')
            ->assertOk()
            ->assertJsonPath('data.0.id', $practice->id)
            ->assertJsonPath('data.0.context_key', 'practice')
            ->assertJsonPath('data.0.context_label', 'practice');
        $this->assertSame(1, $practiceOnly->json('meta.total'));

        $fullOnly = $this->withToken($token)
            ->getJson('/api/v1/teacher/writing-submissions?evaluation_status=pending&context=full_mock')
            ->assertOk()
            ->assertJsonPath('data.0.id', $fullMock->id)
            ->assertJsonPath('data.0.context_key', $fullMockKey)
            ->assertJsonPath('data.0.context_label', 'full_mock');
        $this->assertSame(1, $fullOnly->json('meta.total'));
    }

    public function test_teacher_speaking_queue_filters_by_context_and_exposes_labels(): void
    {
        $task = SpeakingTask::query()->create([
            'part' => SpeakingTask::PART_2,
            'title' => 'Context speaking',
            'instructions' => 'Speak.',
            'preparation_seconds' => 60,
            'response_seconds' => 120,
            'is_published' => true,
        ]);

        $student = $this->userWithRole('student');
        $teacher = $this->userWithRole('teacher');

        $practice = SpeakingRecording::query()->create([
            'speaking_task_id' => $task->id,
            'user_id' => $student->id,
            'disk' => 'local',
            'path' => 'speaking-recordings/practice.webm',
            'mime' => 'audio/webm',
            'size_bytes' => 1000,
            'duration_seconds' => 30,
            'status' => SpeakingRecording::STATUS_SUBMITTED,
            'context_key' => SpeakingRecording::CONTEXT_PRACTICE,
            'submitted_at' => now()->subMinute(),
        ]);

        $fullMock = SpeakingRecording::query()->create([
            'speaking_task_id' => $task->id,
            'user_id' => $student->id,
            'disk' => 'local',
            'path' => 'speaking-recordings/full.webm',
            'mime' => 'audio/webm',
            'size_bytes' => 1200,
            'duration_seconds' => 40,
            'status' => SpeakingRecording::STATUS_SUBMITTED,
            'context_key' => 'fmss:99999',
            'submitted_at' => now(),
        ]);

        Auth::forgetGuards();
        $token = $teacher->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/teacher/speaking-recordings?evaluation_status=pending&context=practice')
            ->assertOk()
            ->assertJsonPath('data.0.id', $practice->id)
            ->assertJsonPath('data.0.context_label', 'practice');

        $this->withToken($token)
            ->getJson('/api/v1/teacher/speaking-recordings?evaluation_status=pending&context=full_mock')
            ->assertOk()
            ->assertJsonPath('data.0.id', $fullMock->id)
            ->assertJsonPath('data.0.context_label', 'full_mock');
    }

    public function test_writing_evaluation_notification_includes_full_mock_context_deep_link(): void
    {
        Notification::fake();

        $task = WritingTask::query()->create([
            'exam_type' => 'academic',
            'task_type' => WritingTask::TYPE_TASK_2,
            'title' => 'Notify essay',
            'prompt' => 'Discuss.',
            'min_words' => 50,
            'is_published' => true,
        ]);

        $student = $this->userWithRole('student');
        $teacher = $this->userWithRole('teacher');
        $sessionSection = $this->makeWritingSessionSection($student, $task);
        $contextKey = $sessionSection->contextKey();
        $sessionUuid = $sessionSection->session->uuid;

        $submission = WritingSubmission::query()->create([
            'writing_task_id' => $task->id,
            'user_id' => $student->id,
            'body' => implode(' ', array_fill(0, 55, 'word')),
            'word_count' => 55,
            'status' => WritingSubmission::STATUS_SUBMITTED,
            'context_key' => $contextKey,
            'submitted_at' => now(),
        ]);

        app(WritingEvaluationService::class)->evaluate($teacher, $submission, [
            'task_response' => 6.5,
            'coherence' => 6.0,
            'lexical' => 6.0,
            'grammar' => 6.0,
            'overall_band' => 6.5,
            'feedback' => 'Good.',
        ]);

        Notification::assertSentTo($student, WritingEvaluationCompletedNotification::class, function (WritingEvaluationCompletedNotification $notification) use ($task, $contextKey, $sessionUuid) {
            $payload = $notification->toDatabase($notification->submission->user);
            $expectedUrl = '/writing-tasks/'.$task->id.'?'.http_build_query([
                'context_key' => $contextKey,
                'session' => $sessionUuid,
            ]);

            return ($payload['context_key'] ?? null) === $contextKey
                && ($payload['context_label'] ?? null) === 'full_mock'
                && ($payload['session_uuid'] ?? null) === $sessionUuid
                && ($payload['url'] ?? null) === $expectedUrl;
        });
    }

    private function makeWritingSessionSection(User $student, WritingTask $task): FullMockSessionSection
    {
        $exam = FullMockExam::query()->create([
            'exam_type' => 'academic',
            'title' => 'Context Full Mock',
            'slug' => 'context-full-mock-'.Str::lower(Str::random(6)),
            'is_published' => true,
            'is_free' => true,
            'allow_resume' => true,
        ]);

        $examSection = FullMockExamSection::query()->create([
            'full_mock_exam_id' => $exam->id,
            'skill' => FullMockExamSection::SKILL_WRITING,
            'title' => 'Writing',
            'position' => 1,
            'duration_seconds' => 1200,
            'writing_task_id' => $task->id,
        ]);

        $session = FullMockSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'full_mock_exam_id' => $exam->id,
            'user_id' => $student->id,
            'status' => FullMockSession::STATUS_IN_PROGRESS,
            'current_position' => 1,
            'started_at' => now(),
        ]);

        return FullMockSessionSection::query()->create([
            'full_mock_session_id' => $session->id,
            'full_mock_exam_section_id' => $examSection->id,
            'skill' => FullMockExamSection::SKILL_WRITING,
            'title' => 'Writing',
            'position' => 1,
            'duration_seconds' => 1200,
            'status' => FullMockSessionSection::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ])->load('session');
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->value('id'));
        Auth::forgetGuards();

        return $user;
    }
}
