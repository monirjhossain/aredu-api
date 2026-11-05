<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Enrollment;
use App\Models\Role;
use App\Models\SpeakingQuestion;
use App\Models\SpeakingTask;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SpeakingTaskManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_only_speaking_managers_can_administer_tasks(): void
    {
        $this->getJson('/api/v1/admin/speaking-tasks')->assertUnauthorized();

        Auth::forgetGuards();
        $this->withToken($this->tokenFor('student'))
            ->postJson('/api/v1/admin/speaking-tasks', $this->taskPayload())
            ->assertForbidden();
    }

    public function test_admin_can_manage_parts_questions_and_publish(): void
    {
        Auth::forgetGuards();
        $adminToken = $this->tokenFor('super_admin');

        $created = $this->withToken($adminToken)
            ->postJson('/api/v1/admin/speaking-tasks', $this->taskPayload([
                'title' => 'Hometown talk',
                'part' => SpeakingTask::PART_1,
                'preparation_seconds' => 0,
                'response_seconds' => 60,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.part', 1)
            ->assertJsonPath('data.is_published', false);

        $taskId = $created->json('data.id');

        $this->withToken($adminToken)
            ->putJson("/api/v1/admin/speaking-tasks/{$taskId}/publication", ['is_published' => true])
            ->assertStatus(422);

        $q1 = $this->withToken($adminToken)
            ->postJson("/api/v1/admin/speaking-tasks/{$taskId}/questions", [
                'prompt' => 'Where do you live?',
                'position' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.prompt', 'Where do you live?');

        $questionId = $q1->json('data.id');

        $this->withToken($adminToken)
            ->putJson("/api/v1/admin/speaking-tasks/{$taskId}/questions/{$questionId}", [
                'prompt' => 'Do you like your hometown?',
                'cue_card' => null,
                'position' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.prompt', 'Do you like your hometown?');

        $part2 = $this->withToken($adminToken)
            ->postJson('/api/v1/admin/speaking-tasks', $this->taskPayload([
                'title' => 'Describe a place',
                'part' => SpeakingTask::PART_2,
                'preparation_seconds' => 60,
                'response_seconds' => 120,
            ]))
            ->assertCreated();

        $part2Id = $part2->json('data.id');
        $cue = $this->withToken($adminToken)
            ->postJson("/api/v1/admin/speaking-tasks/{$part2Id}/questions", [
                'prompt' => 'Describe a place you enjoy visiting.',
                'cue_card' => "You should say:\n- where it is\n- how often you go\n- why you like it",
                'position' => 1,
            ])
            ->assertCreated();
        $this->assertStringContainsString('where it is', (string) $cue->json('data.cue_card'));

        $this->withToken($adminToken)
            ->putJson("/api/v1/admin/speaking-tasks/{$taskId}/publication", ['is_published' => true])
            ->assertOk()
            ->assertJsonPath('data.is_published', true);

        Auth::forgetGuards();
        $student = $this->userWithRole('student');
        $this->enroll($student);
        $studentToken = $student->createToken('api')->plainTextToken;

        $this->withToken($studentToken)
            ->getJson('/api/v1/speaking-tasks')
            ->assertOk()
            ->assertJsonPath('data.0.id', $taskId);

        Auth::forgetGuards();
        $this->withToken($studentToken)
            ->getJson("/api/v1/speaking-tasks/{$taskId}")
            ->assertOk()
            ->assertJsonPath('data.questions.0.prompt', 'Do you like your hometown?');

        Auth::forgetGuards();
        $this->withToken($studentToken)
            ->getJson("/api/v1/speaking-tasks/{$part2Id}")
            ->assertNotFound();

        Auth::forgetGuards();
        $this->withToken($adminToken)
            ->postJson('/api/v1/admin/speaking-tasks', $this->taskPayload([
                'part' => 4,
            ]))
            ->assertStatus(422);

        $this->assertSame(2, SpeakingTask::query()->count());
        $this->assertSame(2, SpeakingQuestion::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function taskPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Sample speaking',
            'part' => SpeakingTask::PART_1,
            'instructions' => 'Answer the questions.',
            'preparation_seconds' => 0,
            'response_seconds' => 60,
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

    private function enroll(User $student): void
    {
        $category = CourseCategory::query()->firstOrCreate(
            ['slug' => 'practice-access'],
            ['name' => 'Practice Access'],
        );
        $course = Course::query()->create([
            'category_id' => $category->id,
            'title' => 'Access Course',
            'slug' => 'access-'.uniqid(),
            'exam_type' => 'academic',
            'price' => 0,
            'is_published' => true,
        ]);
        Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => Enrollment::ACTIVE,
            'enrolled_at' => now(),
        ]);
    }
}
