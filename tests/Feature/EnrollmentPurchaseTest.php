<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Models\Video;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnrollmentPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config([
            'services.sslcommerz.store_id' => 'testbox',
            'services.sslcommerz.store_password' => 'qwerty',
            'services.sslcommerz.sandbox' => true,
            'services.cloudflare.account_id' => 'account-1',
            'services.cloudflare.stream_token' => 'cf-token',
            'services.cloudflare.customer_code' => 'demo123',
            'services.cloudflare.playback_ttl_seconds' => 900,
            'app.url' => 'http://localhost',
            'app.frontend_url' => 'http://localhost:5173',
        ]);
    }

    public function test_payment_to_enrollment_to_access_and_admin_refund(): void
    {
        $student = $this->userWithRole('student');
        $admin = $this->userWithRole('super_admin');
        $course = $this->publishedCourseWithReadyLesson();
        $lesson = $course->sections->first()->lessons->first();

        $order = Order::query()->create([
            'user_id' => $student->id,
            'order_number' => 'ORD-ACCESS-1',
            'status' => Order::PENDING,
            'subtotal' => '1500.00',
            'discount_total' => '0.00',
            'total' => '1500.00',
            'currency' => 'BDT',
        ]);
        $order->items()->create([
            'course_id' => $course->id,
            'unit_price' => '1500.00',
            'discount_price' => null,
        ]);

        Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => Payment::GATEWAY_SSLCOMMERZ,
            'status' => Payment::INITIATED,
            'amount' => '1500.00',
            'currency' => 'BDT',
            'gateway_transaction_id' => 'TRAN-ENROLL-1',
        ]);

        $studentToken = $student->createToken('api')->plainTextToken;

        $this->withToken($studentToken)
            ->getJson('/api/v1/student/courses/'.$course->slug)
            ->assertForbidden();

        $this->withToken($studentToken)
            ->getJson('/api/v1/lessons/'.$lesson->id.'/playback')
            ->assertForbidden();

        Http::fake([
            'sandbox.sslcommerz.com/validator/api/validationserverAPI.php*' => Http::response([
                'status' => 'VALID',
                'tran_id' => 'TRAN-ENROLL-1',
                'val_id' => 'VAL-1',
                'currency_amount' => '1500.00',
                'currency_type' => 'BDT',
                'bank_tran_id' => 'BANK-1',
            ]),
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['token' => 'play-token'],
            ]),
        ]);

        $this->post('/api/v1/payments/sslcommerz/ipn', [
            'status' => 'VALID',
            'tran_id' => 'TRAN-ENROLL-1',
            'val_id' => 'VAL-1',
            'currency_amount' => '1500.00',
            'currency_type' => 'BDT',
        ])->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertDatabaseHas('enrollments', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);

        Auth::forgetGuards();

        $this->withToken($studentToken)
            ->getJson('/api/v1/student/enrollments')
            ->assertOk()
            ->assertJsonPath('data.0.course.slug', $course->slug)
            ->assertJsonPath('data.0.status', 'active');

        $this->withToken($studentToken)
            ->getJson('/api/v1/student/courses/'.$course->slug)
            ->assertOk()
            ->assertJsonPath('data.is_enrolled', true)
            ->assertJsonPath('data.sections.0.lessons.0.id', $lesson->id);

        $this->withToken($studentToken)
            ->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payments.0.status', 'paid');

        $this->withToken($studentToken)
            ->getJson('/api/v1/lessons/'.$lesson->id.'/playback')
            ->assertOk()
            ->assertJsonPath('data.playback.token', 'play-token');

        Auth::forgetGuards();

        $paymentId = Payment::query()->value('id');

        $this->withToken($studentToken)
            ->postJson('/api/v1/admin/payments/'.$paymentId.'/refund')
            ->assertForbidden();

        Auth::forgetGuards();

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->postJson('/api/v1/admin/payments/'.$paymentId.'/refund')
            ->assertOk()
            ->assertJsonPath('data.status', 'refunded');

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame('refunded', Enrollment::query()->value('status'));

        Auth::forgetGuards();

        $this->withToken($studentToken)
            ->getJson('/api/v1/student/courses/'.$course->slug)
            ->assertForbidden();

        $this->withToken($studentToken)
            ->getJson('/api/v1/lessons/'.$lesson->id.'/playback')
            ->assertForbidden();

        $this->withToken($studentToken)
            ->getJson('/api/v1/student/enrollments')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_failed_payment_never_creates_enrollment(): void
    {
        $student = $this->userWithRole('student');
        $course = $this->publishedCourseWithReadyLesson();
        $order = Order::query()->create([
            'user_id' => $student->id,
            'order_number' => 'ORD-FAIL-1',
            'status' => Order::PENDING,
            'subtotal' => '1500.00',
            'discount_total' => '0.00',
            'total' => '1500.00',
            'currency' => 'BDT',
        ]);
        $order->items()->create([
            'course_id' => $course->id,
            'unit_price' => '1500.00',
            'discount_price' => null,
        ]);
        Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => Payment::GATEWAY_SSLCOMMERZ,
            'status' => Payment::INITIATED,
            'amount' => '1500.00',
            'currency' => 'BDT',
            'gateway_transaction_id' => 'TRAN-FAIL-ENROLL',
        ]);

        $this->post('/api/v1/payments/sslcommerz/fail', [
            'tran_id' => 'TRAN-FAIL-ENROLL',
            'status' => 'FAILED',
        ])->assertRedirect();

        $this->assertSame(0, Enrollment::query()->count());
        $this->assertSame('failed', $order->fresh()->status);
    }

    private function publishedCourseWithReadyLesson(): Course
    {
        $category = CourseCategory::query()->firstOrCreate(
            ['slug' => 'academic'],
            ['name' => 'Academic IELTS'],
        );

        $course = Course::query()->create([
            'category_id' => $category->id,
            'title' => 'Writing Access',
            'slug' => 'writing-access',
            'exam_type' => 'academic',
            'price' => '1500.00',
            'duration_minutes' => 60,
            'is_published' => true,
        ]);

        $section = CourseSection::query()->create([
            'course_id' => $course->id,
            'title' => 'Intro',
            'position' => 1,
        ]);

        $lesson = Lesson::query()->create([
            'course_section_id' => $section->id,
            'title' => 'Lesson One',
            'position' => 1,
            'duration_seconds' => 120,
            'is_free_preview' => false,
            'is_published' => true,
        ]);

        Video::query()->create([
            'lesson_id' => $lesson->id,
            'cloudflare_uid' => 'uid-access-1',
            'status' => Video::READY,
            'duration_seconds' => 120,
        ]);

        return $course->load(['sections.lessons']);
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->value('id'));

        return $user;
    }
}
