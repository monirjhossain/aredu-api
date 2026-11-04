<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminStudentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_admin_can_list_students_and_open_detail(): void
    {
        $admin = $this->userWithRole('super_admin');
        $student = $this->userWithRole('student');
        $staff = $this->userWithRole('teacher');
        $course = $this->course('writing', '1200.00');

        $order = Order::query()->create([
            'user_id' => $student->id,
            'order_number' => 'ORD-STU-1',
            'status' => Order::PAID,
            'subtotal' => '1200.00',
            'discount_total' => '0.00',
            'total' => '1200.00',
            'currency' => 'BDT',
        ]);
        $order->items()->create([
            'course_id' => $course->id,
            'unit_price' => '1200.00',
            'discount_price' => null,
        ]);
        Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'order_id' => $order->id,
            'status' => Enrollment::ACTIVE,
            'enrolled_at' => now(),
        ]);

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->getJson('/api/v1/admin/students')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $student->id)
            ->assertJsonPath('data.0.orders_count', 1)
            ->assertJsonPath('data.0.active_enrollments_count', 1);

        $this->assertFalse(
            collect($this->withToken($admin->createToken('api')->plainTextToken)
                ->getJson('/api/v1/admin/students')
                ->json('data'))->contains(fn ($row) => (int) $row['id'] === $staff->id)
        );

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->getJson('/api/v1/admin/students/'.$student->id)
            ->assertOk()
            ->assertJsonPath('data.student.email', $student->email)
            ->assertJsonPath('data.stats.active_enrollments', 1)
            ->assertJsonPath('data.enrollments.0.course.title', 'Writing')
            ->assertJsonPath('data.orders.0.order_number', 'ORD-STU-1');
    }

    public function test_student_cannot_list_admin_students(): void
    {
        $student = $this->userWithRole('student');

        $this->withToken($student->createToken('api')->plainTextToken)
            ->getJson('/api/v1/admin/students')
            ->assertForbidden();
    }

    private function course(string $slug, string $price): Course
    {
        $category = CourseCategory::query()->firstOrCreate(
            ['slug' => 'academic'],
            ['name' => 'Academic IELTS'],
        );

        return Course::query()->create([
            'category_id' => $category->id,
            'title' => ucfirst($slug),
            'slug' => $slug,
            'exam_type' => 'academic',
            'price' => $price,
            'duration_minutes' => 60,
            'is_published' => true,
        ]);
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create([
            'phone' => '017'.random_int(10000000, 99999999),
        ]);
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->value('id'));

        return $user;
    }
}
