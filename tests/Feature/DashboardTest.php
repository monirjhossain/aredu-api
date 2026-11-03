<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SpeakingRecording;
use App\Models\SpeakingTask;
use App\Models\User;
use App\Models\WritingEvaluation;
use App\Models\WritingSubmission;
use App\Models\WritingTask;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_dashboard_is_limited_to_users_who_can_view_reports(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();

        $this->withToken($this->tokenFor('student'))
            ->getJson('/api/v1/admin/dashboard')
            ->assertForbidden();

        $this->withToken($this->tokenFor('teacher'))
            ->getJson('/api/v1/admin/dashboard')
            ->assertForbidden();

        $viewer = User::factory()->create();
        $role = Role::query()->create(['name' => 'Analyst', 'slug' => 'analyst']);
        $role->permissions()->attach(Permission::query()->where('slug', 'reports.view')->value('id'));
        $viewer->roles()->attach($role->id);
        Auth::forgetGuards();

        $this->withToken($viewer->createToken('api')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.students', 1)
            ->assertJsonPath('data.teachers', 1)
            ->assertJsonPath('data.revenue', []);
    }

    public function test_dashboard_uses_real_rows_and_ignores_unpaid_or_closed_records(): void
    {
        $admin = $this->userWithRole('super_admin');
        $student = $this->userWithRole('student');
        $otherStudent = $this->userWithRole('student');
        $teacher = $this->userWithRole('teacher');

        $categoryId = DB::table('course_categories')->insertGetId([
            'name' => 'Academic',
            'slug' => 'academic',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $course = Course::query()->create([
            'category_id' => $categoryId,
            'title' => 'Writing course',
            'slug' => 'writing-course',
            'exam_type' => 'academic',
            'price' => 1500,
            'is_published' => false,
        ]);
        $removed = Course::query()->create([
            'category_id' => $categoryId,
            'title' => 'Removed course',
            'slug' => 'removed-course',
            'exam_type' => 'academic',
            'price' => 500,
        ]);
        $removed->delete();

        Order::query()->create($this->order($student, 'ORD-PAID-1', 'paid', '100.00'));
        Order::query()->create($this->order($otherStudent, 'ORD-PAID-2', 'paid', '50.50'));
        Order::query()->create($this->order($student, 'ORD-PENDING', 'pending', '999.00'));
        Order::query()->create($this->order($student, 'ORD-REFUNDED', 'refunded', '20.00'));
        $latest = Order::query()->create($this->order($student, 'ORD-LATEST', 'failed', '10.00'));

        Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);
        Enrollment::query()->create([
            'user_id' => $otherStudent->id,
            'course_id' => $course->id,
            'status' => 'refunded',
            'enrolled_at' => now(),
        ]);

        $writingTask = WritingTask::query()->create([
            'exam_type' => 'academic',
            'task_type' => 'task_2',
            'title' => 'Essay',
            'prompt' => 'Discuss both views.',
        ]);
        WritingSubmission::query()->create([
            'writing_task_id' => $writingTask->id,
            'user_id' => $student->id,
            'body' => 'Pending essay',
            'word_count' => 200,
            'status' => 'submitted',
            'submitted_at' => now()->subHour(),
        ]);
        $evaluated = WritingSubmission::query()->create([
            'writing_task_id' => $writingTask->id,
            'user_id' => $otherStudent->id,
            'body' => 'Done essay',
            'word_count' => 220,
            'status' => 'submitted',
            'submitted_at' => now()->subHours(2),
        ]);
        WritingEvaluation::query()->create([
            'writing_submission_id' => $evaluated->id,
            'teacher_id' => $teacher->id,
            'task_response' => 6.0,
            'coherence' => 6.0,
            'lexical' => 6.0,
            'grammar' => 6.0,
            'overall_band' => 6.0,
            'evaluated_at' => now(),
        ]);
        WritingSubmission::query()->create([
            'writing_task_id' => $writingTask->id,
            'user_id' => $student->id,
            'body' => 'Draft',
            'status' => 'draft',
        ]);

        $speakingTask = SpeakingTask::query()->create([
            'part' => 1,
            'title' => 'Part 1',
        ]);
        SpeakingRecording::query()->create([
            'speaking_task_id' => $speakingTask->id,
            'user_id' => $student->id,
            'disk' => 'local',
            'path' => 'speaking/one.webm',
            'mime' => 'audio/webm',
            'size_bytes' => 1000,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        SpeakingRecording::query()->create([
            'speaking_task_id' => $speakingTask->id,
            'user_id' => $student->id,
            'disk' => 'local',
            'path' => 'speaking/draft.webm',
            'mime' => 'audio/webm',
            'size_bytes' => 500,
            'status' => 'uploaded',
        ]);

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.students', 2)
            ->assertJsonPath('data.teachers', 1)
            ->assertJsonPath('data.courses', 1)
            ->assertJsonPath('data.enrollments', 1)
            ->assertJsonPath('data.revenue.0.currency', 'BDT')
            ->assertJsonPath('data.revenue.0.total', '150.50')
            ->assertJsonPath('data.recent_orders.0.order_number', $latest->order_number)
            ->assertJsonPath('data.recent_registrations.0.id', $otherStudent->id)
            ->assertJsonPath('data.pending_evaluations.total', 2)
            ->assertJsonPath('data.pending_evaluations.items.0.type', 'speaking');
    }

    /**
     * @return array<string, mixed>
     */
    private function order(User $user, string $number, string $status, string $total): array
    {
        return [
            'user_id' => $user->id,
            'order_number' => $number,
            'status' => $status,
            'subtotal' => $total,
            'discount_total' => 0,
            'total' => $total,
            'currency' => 'BDT',
        ];
    }

    private function tokenFor(string $roleSlug): string
    {
        return $this->userWithRole($roleSlug)->createToken('api')->plainTextToken;
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->value('id'));

        return $user;
    }
}
