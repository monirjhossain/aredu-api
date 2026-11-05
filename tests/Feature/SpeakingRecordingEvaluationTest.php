<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Enrollment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SpeakingEvaluation;
use App\Models\SpeakingQuestion;
use App\Models\SpeakingRecording;
use App\Models\SpeakingTask;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SpeakingRecordingEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_student_can_upload_submit_and_teacher_can_evaluate_privately(): void
    {
        $task = SpeakingTask::query()->create([
            'part' => SpeakingTask::PART_2,
            'title' => 'Describe a city',
            'instructions' => 'Speak clearly.',
            'preparation_seconds' => 60,
            'response_seconds' => 120,
            'is_published' => true,
        ]);
        SpeakingQuestion::query()->create([
            'speaking_task_id' => $task->id,
            'prompt' => 'Describe a city you like.',
            'cue_card' => 'Say where and why.',
            'position' => 1,
        ]);

        $student = $this->userWithRole('student');
        $this->enroll($student);
        $teacher = $this->userWithRole('teacher');
        $other = $this->userWithRole('student');
        $unassigned = $this->userWithPermission('courses.view_assigned');

        Auth::forgetGuards();
        $studentToken = $student->createToken('api')->plainTextToken;

        $uploaded = $this->withToken($studentToken)
            ->post("/api/v1/speaking-tasks/{$task->id}/recording", [
                'recording' => UploadedFile::fake()->create('answer.webm', 120, 'audio/webm'),
                'duration_seconds' => 45,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'uploaded')
            ->assertJsonPath('data.duration_seconds', 45);

        $recordingId = $uploaded->json('data.id');
        $this->assertTrue(Storage::disk('local')->exists(SpeakingRecording::query()->find($recordingId)->path));

        Auth::forgetGuards();
        $this->withToken($other->createToken('api')->plainTextToken)
            ->get("/api/v1/speaking-recordings/{$recordingId}/audio")
            ->assertForbidden();

        Auth::forgetGuards();
        $this->withToken($studentToken)
            ->get("/api/v1/speaking-recordings/{$recordingId}/audio")
            ->assertOk();

        Auth::forgetGuards();
        $this->withToken($studentToken)
            ->postJson("/api/v1/speaking-tasks/{$task->id}/recording/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        Auth::forgetGuards();
        $this->withToken($studentToken)
            ->post("/api/v1/speaking-tasks/{$task->id}/recording", [
                'recording' => UploadedFile::fake()->create('again.webm', 80, 'audio/webm'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        Auth::forgetGuards();
        $this->withToken($unassigned->createToken('api')->plainTextToken)
            ->getJson('/api/v1/teacher/speaking-recordings')
            ->assertForbidden();

        Auth::forgetGuards();
        $teacherToken = $teacher->createToken('api')->plainTextToken;

        $this->withToken($teacherToken)
            ->getJson('/api/v1/teacher/speaking-recordings?evaluation_status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.id', $recordingId);

        Auth::forgetGuards();
        $this->withToken($teacherToken)
            ->get("/api/v1/speaking-recordings/{$recordingId}/audio")
            ->assertOk();

        Auth::forgetGuards();
        $created = $this->withToken($teacherToken)
            ->postJson("/api/v1/teacher/speaking-recordings/{$recordingId}/evaluate", [
                'fluency' => 6.5,
                'lexical' => 6.0,
                'grammar' => 6.5,
                'pronunciation' => 6.0,
                'overall_band' => 6.5,
                'feedback' => 'Good fluency with minor pronunciation issues.',
            ])
            ->assertCreated();
        $this->assertEqualsWithDelta(6.5, (float) $created->json('data.overall_band'), 0.01);

        Auth::forgetGuards();
        $otherTeacher = $this->userWithRole('teacher');
        $this->withToken($otherTeacher->createToken('api')->plainTextToken)
            ->putJson('/api/v1/teacher/speaking-evaluations/'.$created->json('data.id'), [
                'fluency' => 7.0,
                'lexical' => 7.0,
                'grammar' => 7.0,
                'pronunciation' => 7.0,
                'overall_band' => 7.0,
            ])
            ->assertForbidden();

        Auth::forgetGuards();
        $studentView = $this->withToken($studentToken)
            ->getJson("/api/v1/student/speaking-recordings/{$recordingId}")
            ->assertOk()
            ->assertJsonPath('data.evaluation_status', 'evaluated')
            ->assertJsonPath('data.evaluation.feedback', 'Good fluency with minor pronunciation issues.');
        $this->assertEqualsWithDelta(6.5, (float) $studentView->json('data.evaluation.overall_band'), 0.01);

        $this->assertSame(1, SpeakingEvaluation::query()->count());
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

    private function userWithPermission(string $permissionSlug): User
    {
        $role = Role::query()->create([
            'name' => 'Limited '.$permissionSlug,
            'slug' => 'limited_'.str_replace('.', '_', $permissionSlug).'_'.uniqid(),
        ]);
        $role->permissions()->attach(Permission::query()->where('slug', $permissionSlug)->value('id'));
        $user = User::factory()->create();
        $user->roles()->attach($role->id);
        Auth::forgetGuards();

        return $user;
    }
}
