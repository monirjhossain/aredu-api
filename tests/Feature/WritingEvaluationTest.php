<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WritingEvaluation;
use App\Models\WritingSubmission;
use App\Models\WritingTask;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class WritingEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_only_assigned_evaluators_can_score_and_students_see_returned_bands(): void
    {
        $task = WritingTask::query()->create([
            'exam_type' => 'academic',
            'task_type' => WritingTask::TYPE_TASK_2,
            'title' => 'Opinion essay',
            'prompt' => 'Discuss both views.',
            'recommended_minutes' => 40,
            'min_words' => 50,
            'is_published' => true,
        ]);

        $student = $this->userWithRole('student');
        $teacher = $this->userWithRole('teacher');
        $otherTeacher = $this->userWithRole('teacher');
        $unassigned = $this->userWithPermission('courses.view_assigned');

        $body = implode(' ', array_fill(0, 55, 'word'));
        $submission = WritingSubmission::query()->create([
            'writing_task_id' => $task->id,
            'user_id' => $student->id,
            'body' => $body,
            'word_count' => 55,
            'status' => WritingSubmission::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        Auth::forgetGuards();
        $this->withToken($student->createToken('api')->plainTextToken)
            ->postJson("/api/v1/teacher/writing-submissions/{$submission->id}/evaluate", $this->scorePayload())
            ->assertForbidden();

        Auth::forgetGuards();
        $this->withToken($unassigned->createToken('api')->plainTextToken)
            ->getJson('/api/v1/teacher/writing-submissions')
            ->assertForbidden();

        Auth::forgetGuards();
        $this->withToken($unassigned->createToken('api')->plainTextToken)
            ->postJson("/api/v1/teacher/writing-submissions/{$submission->id}/evaluate", $this->scorePayload())
            ->assertForbidden();

        Auth::forgetGuards();
        $teacherToken = $teacher->createToken('api')->plainTextToken;

        $this->withToken($teacherToken)
            ->getJson('/api/v1/teacher/writing-submissions?evaluation_status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $submission->id)
            ->assertJsonPath('data.0.body', $body);

        $created = $this->withToken($teacherToken)
            ->postJson("/api/v1/teacher/writing-submissions/{$submission->id}/evaluate", $this->scorePayload([
                'task_response' => 6.5,
                'coherence' => 6.0,
                'lexical' => 6.5,
                'grammar' => 6.0,
                'overall_band' => 6.5,
                'feedback' => 'Clear position with some cohesion issues.',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.overall_band', 6.5)
            ->assertJsonPath('data.teacher_id', $teacher->id);

        $evaluationId = $created->json('data.id');

        Auth::forgetGuards();
        $this->withToken($otherTeacher->createToken('api')->plainTextToken)
            ->postJson("/api/v1/teacher/writing-submissions/{$submission->id}/evaluate", $this->scorePayload())
            ->assertStatus(422);

        Auth::forgetGuards();
        $this->withToken($otherTeacher->createToken('api')->plainTextToken)
            ->putJson("/api/v1/teacher/writing-evaluations/{$evaluationId}", $this->scorePayload([
                'overall_band' => 7.0,
            ]))
            ->assertForbidden();

        Auth::forgetGuards();
        $updated = $this->withToken($teacherToken)
            ->putJson("/api/v1/teacher/writing-evaluations/{$evaluationId}", $this->scorePayload([
                'task_response' => 7.0,
                'coherence' => 6.5,
                'lexical' => 6.5,
                'grammar' => 6.5,
                'overall_band' => 6.5,
                'feedback' => 'Revised feedback.',
            ]))
            ->assertOk()
            ->assertJsonPath('data.feedback', 'Revised feedback.');
        $this->assertEqualsWithDelta(7.0, (float) $updated->json('data.task_response'), 0.01);

        Auth::forgetGuards();
        $studentView = $this->withToken($student->createToken('api')->plainTextToken)
            ->getJson("/api/v1/student/writing-submissions/{$submission->id}/evaluation")
            ->assertOk()
            ->assertJsonPath('data.evaluation_status', 'evaluated')
            ->assertJsonPath('data.evaluation.feedback', 'Revised feedback.');
        $this->assertEqualsWithDelta(6.5, (float) $studentView->json('data.evaluation.overall_band'), 0.01);
        $this->assertEqualsWithDelta(7.0, (float) $studentView->json('data.evaluation.task_response'), 0.01);

        $otherStudent = $this->userWithRole('student');
        Auth::forgetGuards();
        $this->withToken($otherStudent->createToken('api')->plainTextToken)
            ->getJson("/api/v1/student/writing-submissions/{$submission->id}/evaluation")
            ->assertForbidden();

        $invalidTarget = WritingSubmission::query()->create([
            'writing_task_id' => $task->id,
            'user_id' => $otherStudent->id,
            'body' => $body,
            'word_count' => 55,
            'status' => WritingSubmission::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        Auth::forgetGuards();
        $this->withToken($teacherToken)
            ->postJson("/api/v1/teacher/writing-submissions/{$invalidTarget->id}/evaluate", $this->scorePayload([
                'overall_band' => 6.25,
            ]))
            ->assertStatus(422);

        $draftTask = WritingTask::query()->create([
            'exam_type' => 'academic',
            'task_type' => WritingTask::TYPE_TASK_2,
            'title' => 'Draft-only writing task',
            'instructions' => null,
            'prompt' => 'Draft prompt',
            'recommended_minutes' => 40,
            'min_words' => 250,
            'is_published' => true,
        ]);

        $draft = WritingSubmission::query()->create([
            'writing_task_id' => $draftTask->id,
            'user_id' => $otherStudent->id,
            'body' => 'draft only',
            'word_count' => 2,
            'status' => WritingSubmission::STATUS_DRAFT,
            'context_key' => WritingSubmission::CONTEXT_PRACTICE,
        ]);

        Auth::forgetGuards();
        $this->withToken($teacherToken)
            ->postJson("/api/v1/teacher/writing-submissions/{$draft->id}/evaluate", $this->scorePayload())
            ->assertStatus(422);

        $this->assertSame(1, WritingEvaluation::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function scorePayload(array $overrides = []): array
    {
        return array_merge([
            'task_response' => 6.0,
            'coherence' => 6.0,
            'lexical' => 6.0,
            'grammar' => 6.0,
            'overall_band' => 6.0,
            'feedback' => 'Solid attempt.',
        ], $overrides);
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->value('id'));
        Auth::forgetGuards();

        return $user;
    }

    private function userWithPermission(string $permissionSlug): User
    {
        $role = Role::query()->create([
            'name' => 'Limited '.$permissionSlug,
            'slug' => 'limited_'.str_replace('.', '_', $permissionSlug).'_'.uniqid(),
        ]);
        $permissionId = Permission::query()->where('slug', $permissionSlug)->value('id');
        $role->permissions()->attach($permissionId);

        $user = User::factory()->create();
        $user->roles()->attach($role->id);
        Auth::forgetGuards();

        return $user;
    }
}
