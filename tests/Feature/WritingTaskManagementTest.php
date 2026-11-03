<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\WritingSubmission;
use App\Models\WritingTask;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WritingTaskManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Storage::fake('public');
    }

    public function test_only_writing_managers_can_administer_tasks(): void
    {
        $this->getJson('/api/v1/admin/writing-tasks')->assertUnauthorized();

        $this->withToken($this->tokenFor('student'))
            ->postJson('/api/v1/admin/writing-tasks', $this->taskPayload())
            ->assertForbidden();
    }

    public function test_admin_can_manage_tasks_and_student_can_draft_then_submit(): void
    {
        $adminToken = $this->tokenFor('super_admin');

        $created = $this->withToken($adminToken)
            ->postJson('/api/v1/admin/writing-tasks', $this->taskPayload([
                'title' => 'City Transport Essay',
                'task_type' => WritingTask::TYPE_TASK_2,
                'exam_type' => 'academic',
                'min_words' => 50,
                'recommended_minutes' => 40,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.task_type', 'task_2')
            ->assertJsonPath('data.is_published', false);

        $taskId = $created->json('data.id');

        $image = $this->withToken($adminToken)
            ->post("/api/v1/admin/writing-tasks/{$taskId}/image", [
                'image' => UploadedFile::fake()->image('chart.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertOk();
        $this->assertStringContainsString('storage/', (string) $image->json('data.image_url'));

        $this->withToken($adminToken)
            ->putJson("/api/v1/admin/writing-tasks/{$taskId}/publication", ['is_published' => true])
            ->assertOk()
            ->assertJsonPath('data.is_published', true);

        $this->withToken($adminToken)
            ->postJson('/api/v1/admin/writing-tasks', $this->taskPayload([
                'title' => 'Bad pair',
                'task_type' => WritingTask::TYPE_ACADEMIC_TASK_1,
                'exam_type' => 'general',
            ]))
            ->assertStatus(422);

        $student = $this->userWithRole('student');
        $studentToken = $student->createToken('api')->plainTextToken;

        $this->withToken($studentToken)
            ->getJson('/api/v1/writing-tasks')
            ->assertOk()
            ->assertJsonPath('data.0.id', $taskId);

        $short = 'one two three';
        $this->withToken($studentToken)
            ->putJson("/api/v1/writing-tasks/{$taskId}/submission", ['body' => $short])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.word_count', 3);

        $this->withToken($studentToken)
            ->postJson("/api/v1/writing-tasks/{$taskId}/submission/submit", ['body' => $short])
            ->assertStatus(422);

        $body = implode(' ', array_fill(0, 55, 'word'));
        $submitted = $this->withToken($studentToken)
            ->postJson("/api/v1/writing-tasks/{$taskId}/submission/submit", ['body' => $body])
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.word_count', 55);

        $this->assertNotNull($submitted->json('data.submitted_at'));

        $this->withToken($studentToken)
            ->putJson("/api/v1/writing-tasks/{$taskId}/submission", ['body' => 'changed'])
            ->assertStatus(422);

        $this->withToken($studentToken)
            ->postJson("/api/v1/writing-tasks/{$taskId}/submission/submit", ['body' => $body])
            ->assertStatus(422);

        $other = $this->userWithRole('student');
        $submissionId = WritingSubmission::query()->where('user_id', $student->id)->value('id');
        $this->withToken($other->createToken('api')->plainTextToken)
            ->getJson("/api/v1/student/writing-submissions/{$submissionId}")
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function taskPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Sample writing',
            'exam_type' => 'academic',
            'task_type' => WritingTask::TYPE_TASK_2,
            'instructions' => 'Write an essay.',
            'prompt' => 'Discuss both views and give your opinion.',
            'recommended_minutes' => 40,
            'min_words' => 250,
            'is_published' => false,
        ], $overrides);
    }

    private function tokenFor(string $roleSlug): string
    {
        return $this->userWithRole($roleSlug)->createToken('api')->plainTextToken;
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->value('id'));
        Auth::forgetGuards();

        return $user;
    }
}
