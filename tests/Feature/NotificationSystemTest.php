<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Models\WritingSubmission;
use App\Models\WritingTask;
use App\Notifications\EnrollmentActivatedNotification;
use App\Notifications\PaymentSuccessfulNotification;
use App\Notifications\WritingEvaluationCompletedNotification;
use App\Services\Payment\PaymentService;
use App\Services\Writing\WritingEvaluationService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class NotificationSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_payment_and_enrollment_notify_and_student_can_manage_reads(): void
    {
        $student = $this->userWithRole('student');
        $course = $this->makeCourse();
        $order = Order::query()->create([
            'user_id' => $student->id,
            'order_number' => 'ORD-NOTIFY-1',
            'status' => Order::PENDING,
            'subtotal' => '1000.00',
            'discount_total' => '0.00',
            'total' => '1000.00',
            'currency' => 'BDT',
        ]);
        $order->items()->create([
            'course_id' => $course->id,
            'unit_price' => '1000.00',
            'discount_price' => null,
        ]);
        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => Payment::GATEWAY_SSLCOMMERZ,
            'status' => Payment::INITIATED,
            'amount' => '1000.00',
            'currency' => 'BDT',
            'gateway_transaction_id' => 'tran-notify-1',
        ]);

        $service = app(PaymentService::class);
        $method = new \ReflectionMethod(PaymentService::class, 'markPaid');
        $method->setAccessible(true);
        $method->invoke($service, $payment, 'val-1', 'bank-1');

        $this->assertTrue(
            $student->notifications()->where('type', PaymentSuccessfulNotification::class)->exists()
        );
        $this->assertTrue(
            $student->notifications()->where('type', EnrollmentActivatedNotification::class)->exists()
        );
        $this->assertTrue(Enrollment::query()->where('user_id', $student->id)->where('status', 'active')->exists());

        $task = WritingTask::query()->create([
            'exam_type' => 'academic',
            'task_type' => WritingTask::TYPE_TASK_2,
            'title' => 'Essay',
            'prompt' => 'Discuss.',
            'min_words' => 50,
            'is_published' => true,
        ]);
        $submission = WritingSubmission::query()->create([
            'writing_task_id' => $task->id,
            'user_id' => $student->id,
            'body' => implode(' ', array_fill(0, 55, 'word')),
            'word_count' => 55,
            'status' => WritingSubmission::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);
        $teacher = $this->userWithRole('teacher');
        app(WritingEvaluationService::class)->evaluate($teacher, $submission, [
            'task_response' => 6.5,
            'coherence' => 6.0,
            'lexical' => 6.0,
            'grammar' => 6.0,
            'overall_band' => 6.5,
            'feedback' => 'Good.',
        ]);

        $this->assertTrue(
            $student->notifications()->where('type', WritingEvaluationCompletedNotification::class)->exists()
        );

        Auth::forgetGuards();
        $token = $student->createToken('api')->plainTextToken;

        $list = $this->withToken($token)
            ->getJson('/api/v1/notifications')
            ->assertOk();
        $this->assertGreaterThanOrEqual(3, $list->json('meta.total'));
        $this->assertGreaterThanOrEqual(3, $list->json('meta.unread_count'));
        $id = $list->json('data.0.id');

        $read = $this->withToken($token)
            ->postJson("/api/v1/notifications/{$id}/read")
            ->assertOk();
        $this->assertNotEmpty($read->json('data.read_at'));

        Auth::forgetGuards();
        $other = $this->userWithRole('student');
        $this->withToken($other->createToken('api')->plainTextToken)
            ->postJson("/api/v1/notifications/{$id}/read")
            ->assertForbidden();

        Auth::forgetGuards();
        $this->withToken($token)
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->withToken($token)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    private function makeCourse(): Course
    {
        $category = CourseCategory::query()->create(['name' => 'Cat', 'slug' => 'cat-'.uniqid()]);

        return Course::query()->create([
            'category_id' => $category->id,
            'title' => 'Course',
            'slug' => 'course-'.uniqid(),
            'exam_type' => 'academic',
            'price' => 1000,
            'duration_minutes' => 60,
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
