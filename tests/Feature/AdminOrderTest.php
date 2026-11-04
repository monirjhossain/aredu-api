<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_admin_can_list_orders_and_filter_pending(): void
    {
        $admin = $this->userWithRole('super_admin');
        $student = $this->userWithRole('student');
        $pending = $this->pendingOrder($student, '1200.00', 'writing');
        $paid = $this->pendingOrder($student, '900.00', 'speaking');
        $paid->update(['status' => Order::PAID]);

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->getJson('/api/v1/admin/orders?status=pending')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.user.email', $student->email)
            ->assertJsonPath('data.0.items.0.course_title', 'Writing');
    }

    public function test_admin_can_filter_orders_by_gateway_and_date(): void
    {
        $admin = $this->userWithRole('super_admin');
        $student = $this->userWithRole('student');
        $order = $this->pendingOrder($student, '1200.00', 'writing');
        Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => Payment::GATEWAY_BKASH,
            'status' => Payment::PAID,
            'amount' => '1200.00',
            'currency' => 'BDT',
            'gateway_transaction_id' => 'manual-1',
            'gateway_reference' => 'TRX-1',
        ]);
        $order->update(['status' => Order::PAID]);

        $other = $this->pendingOrder($student, '900.00', 'speaking');
        Payment::query()->create([
            'order_id' => $other->id,
            'gateway' => Payment::GATEWAY_SSLCOMMERZ,
            'status' => Payment::INITIATED,
            'amount' => '900.00',
            'currency' => 'BDT',
            'gateway_transaction_id' => 'ssl-1',
        ]);

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->getJson('/api/v1/admin/orders?gateway=bkash&date_from='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $order->id);
    }

    public function test_student_purchase_creates_order_activity_log(): void
    {
        $student = $this->userWithRole('student');
        $course = $this->course('listening', '1500.00');

        $this->withToken($student->createToken('api')->plainTextToken)
            ->postJson('/api/v1/orders', ['course_ids' => [$course->id]])
            ->assertCreated();

        $this->assertSame(1, Order::query()->count());
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $student->id,
            'action' => 'order.created',
        ]);
        $this->assertSame(1, ActivityLog::query()->where('action', 'order.created')->count());
    }

    public function test_admin_can_confirm_bkash_and_activate_enrollment(): void
    {
        $admin = $this->userWithRole('super_admin');
        $student = $this->userWithRole('student');
        $order = $this->pendingOrder($student, '1500.00', 'writing');

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->postJson('/api/v1/admin/orders/'.$order->id.'/confirm-bkash', [
                'trx_id' => 'TRX-ADMIN-1',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.gateway', 'bkash');

        $this->assertSame(Order::PAID, $order->fresh()->status);
        $this->assertSame(1, Enrollment::query()->where('user_id', $student->id)->where('status', 'active')->count());
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->where('status', Payment::PAID)->count());
    }

    public function test_admin_can_show_and_cancel_pending_order(): void
    {
        $admin = $this->userWithRole('super_admin');
        $student = $this->userWithRole('student');
        $order = $this->pendingOrder($student, '1100.00', 'grammar');

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->getJson('/api/v1/admin/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.user.email', $student->email);

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->postJson('/api/v1/admin/orders/'.$order->id.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', Order::CANCELLED);

        $this->assertSame(Order::CANCELLED, $order->fresh()->status);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $admin->id,
            'action' => 'order.cancelled',
        ]);
    }

    public function test_admin_cannot_cancel_paid_order(): void
    {
        $admin = $this->userWithRole('super_admin');
        $student = $this->userWithRole('student');
        $order = $this->pendingOrder($student, '1100.00', 'vocab');
        $order->update(['status' => Order::PAID]);

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->postJson('/api/v1/admin/orders/'.$order->id.'/cancel')
            ->assertStatus(422);
    }

    public function test_student_cannot_list_admin_orders(): void
    {
        $student = $this->userWithRole('student');

        $this->withToken($student->createToken('api')->plainTextToken)
            ->getJson('/api/v1/admin/orders')
            ->assertForbidden();
    }

    private function pendingOrder(User $user, string $total, string $slug): Order
    {
        $course = $this->course($slug, $total);

        $order = Order::query()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.$slug.'-'.uniqid(),
            'status' => Order::PENDING,
            'subtotal' => $total,
            'discount_total' => '0.00',
            'total' => $total,
            'currency' => 'BDT',
        ]);

        $order->items()->create([
            'course_id' => $course->id,
            'unit_price' => $total,
            'discount_price' => null,
        ]);

        return $order->fresh(['items.course', 'user']);
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
