<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\Enrollment\EnrollmentService;
use App\Services\Report\ReportService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ReportAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_admin_sees_financial_reports_teacher_is_scoped(): void
    {
        $admin = $this->userWithRole('super_admin');
        $teacher = $this->userWithRole('teacher');
        $student = $this->userWithRole('student');
        $otherStudent = $this->userWithRole('student');

        $assigned = $this->makeCourse('Assigned Course');
        $otherCourse = $this->makeCourse('Other Course');
        $assigned->teachers()->attach($teacher->id, ['can_manage_content' => true]);

        Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $assigned->id,
            'status' => EnrollmentService::ACTIVE,
            'enrolled_at' => now()->subDay(),
        ]);
        Enrollment::query()->create([
            'user_id' => $otherStudent->id,
            'course_id' => $otherCourse->id,
            'status' => EnrollmentService::ACTIVE,
            'enrolled_at' => now()->subDay(),
        ]);

        $order = Order::query()->create([
            'user_id' => $student->id,
            'order_number' => 'ORD-RPT-1',
            'status' => Order::PAID,
            'subtotal' => '500.00',
            'discount_total' => '0.00',
            'total' => '500.00',
            'currency' => 'BDT',
        ]);
        $order->items()->create([
            'course_id' => $assigned->id,
            'unit_price' => '500.00',
            'discount_price' => null,
        ]);
        Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => Payment::GATEWAY_SSLCOMMERZ,
            'status' => Payment::PAID,
            'amount' => '500.00',
            'currency' => 'BDT',
            'gateway_transaction_id' => 'tran-rpt-1',
        ]);
        Certificate::query()->create([
            'user_id' => $student->id,
            'course_id' => $assigned->id,
            'certificate_number' => 'CERT-1',
            'issued_at' => now(),
        ]);

        Auth::forgetGuards();
        $adminToken = $admin->createToken('api')->plainTextToken;

        $catalog = $this->withToken($adminToken)
            ->getJson('/api/v1/reports')
            ->assertOk();
        $keys = collect($catalog->json('data'))->pluck('key');
        $this->assertTrue($keys->contains(ReportService::TYPE_REVENUE));
        $this->assertTrue($keys->contains(ReportService::TYPE_ENROLLMENT));

        $this->withToken($adminToken)
            ->getJson('/api/v1/reports/'.ReportService::TYPE_REVENUE)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->withToken($adminToken)
            ->getJson('/api/v1/reports/'.ReportService::TYPE_STUDENT_REGISTRATION)
            ->assertOk();

        $csv = $this->withToken($adminToken)
            ->get('/api/v1/reports/'.ReportService::TYPE_ENROLLMENT.'/export')
            ->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));
        $this->assertStringContainsString('Student', $csv->streamedContent());

        Auth::forgetGuards();
        $teacherToken = $teacher->createToken('api')->plainTextToken;

        $teacherCatalog = $this->withToken($teacherToken)
            ->getJson('/api/v1/reports')
            ->assertOk();
        $teacherKeys = collect($teacherCatalog->json('data'))->pluck('key');
        $this->assertFalse($teacherKeys->contains(ReportService::TYPE_REVENUE));
        $this->assertFalse($teacherKeys->contains(ReportService::TYPE_READING_PERFORMANCE));
        $this->assertFalse($teacherKeys->contains(ReportService::TYPE_WRITING_EVALUATION));
        $this->assertTrue($teacherKeys->contains(ReportService::TYPE_ENROLLMENT));
        $this->assertTrue($teacherKeys->contains(ReportService::TYPE_COURSE_COMPLETION));

        $this->withToken($teacherToken)
            ->getJson('/api/v1/reports/'.ReportService::TYPE_REVENUE)
            ->assertForbidden();

        $this->withToken($teacherToken)
            ->getJson('/api/v1/reports/'.ReportService::TYPE_READING_PERFORMANCE)
            ->assertForbidden();

        $scoped = $this->withToken($teacherToken)
            ->getJson('/api/v1/reports/'.ReportService::TYPE_ENROLLMENT)
            ->assertOk();
        $this->assertSame(1, $scoped->json('meta.total'));
        $this->assertSame($student->name, $scoped->json('data.rows.0.student_name'));

        Auth::forgetGuards();
        $this->withToken($this->userWithRole('student')->createToken('api')->plainTextToken)
            ->getJson('/api/v1/reports')
            ->assertForbidden();
    }

    private function makeCourse(string $title): Course
    {
        $category = CourseCategory::query()->first()
            ?? CourseCategory::query()->create(['name' => 'Cat', 'slug' => 'cat-'.uniqid()]);

        return Course::query()->create([
            'category_id' => $category->id,
            'title' => $title,
            'slug' => 'course-'.uniqid(),
            'exam_type' => 'academic',
            'price' => 500,
            'duration_minutes' => 30,
            'is_published' => true,
        ]);
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->value('id'));
        Auth::forgetGuards();

        return $user;
    }
}
