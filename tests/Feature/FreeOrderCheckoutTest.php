<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class FreeOrderCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_zero_price_course_order_is_auto_settled_and_enrolls(): void
    {
        $course = $this->course('free-writing', '0.00');
        $student = $this->student();
        $token = $student->createToken('api')->plainTextToken;

        $created = $this->withToken($token)
            ->postJson('/api/v1/orders', ['course_ids' => [$course->id]])
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.total', '0.00')
            ->assertJsonPath('data.payments.0.gateway', Payment::GATEWAY_FREE)
            ->assertJsonPath('data.payments.0.status', Payment::PAID);

        $this->assertTrue(
            Enrollment::query()
                ->where('user_id', $student->id)
                ->where('course_id', $course->id)
                ->where('status', 'active')
                ->exists()
        );

        Auth::forgetGuards();
        $this->withToken($token)
            ->postJson('/api/v1/orders/'.$created->json('data.id').'/payments/free')
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertSame(1, Payment::query()->where('gateway', Payment::GATEWAY_FREE)->count());
    }

    public function test_hundred_percent_coupon_auto_settles_order(): void
    {
        $course = $this->course('coupon-writing', '1200.00');
        $student = $this->student();
        $token = $student->createToken('api')->plainTextToken;

        Coupon::query()->create([
            'code' => 'FREE100',
            'type' => 'percent',
            'value' => 100,
            'is_active' => true,
            'max_uses' => 10,
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/orders', [
                'course_ids' => [$course->id],
                'coupon_code' => 'free100',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.subtotal', '1200.00')
            ->assertJsonPath('data.discount_total', '1200.00')
            ->assertJsonPath('data.total', '0.00')
            ->assertJsonPath('data.payments.0.gateway', Payment::GATEWAY_FREE);

        $this->assertTrue(
            Enrollment::query()
                ->where('user_id', $student->id)
                ->where('course_id', $course->id)
                ->where('status', 'active')
                ->exists()
        );
    }

    public function test_pending_zero_total_order_can_be_settled_via_endpoint(): void
    {
        $course = $this->course('legacy-free', '0.00');
        $student = $this->student();

        $order = Order::query()->create([
            'user_id' => $student->id,
            'order_number' => 'ORD-FREE-LEGACY',
            'status' => Order::PENDING,
            'subtotal' => '0.00',
            'discount_total' => '0.00',
            'total' => '0.00',
            'currency' => 'BDT',
        ]);
        $order->items()->create([
            'course_id' => $course->id,
            'unit_price' => '0.00',
            'discount_price' => null,
        ]);

        Auth::forgetGuards();
        $token = $student->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/orders/{$order->id}/payments/sslcommerz")
            ->assertStatus(422);

        $this->withToken($token)
            ->postJson("/api/v1/orders/{$order->id}/payments/bkash")
            ->assertStatus(422);

        $this->withToken($token)
            ->postJson("/api/v1/orders/{$order->id}/payments/free")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payments.0.gateway', Payment::GATEWAY_FREE);

        $this->assertTrue(
            Enrollment::query()
                ->where('user_id', $student->id)
                ->where('course_id', $course->id)
                ->where('status', 'active')
                ->exists()
        );
    }

    public function test_paid_order_cannot_use_free_settle(): void
    {
        $course = $this->course('paid-writing', '1500.00');
        $student = $this->student();
        $token = $student->createToken('api')->plainTextToken;

        $order = $this->withToken($token)
            ->postJson('/api/v1/orders', ['course_ids' => [$course->id]])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data');

        Auth::forgetGuards();
        $this->withToken($token)
            ->postJson('/api/v1/orders/'.$order['id'].'/payments/free')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['order']);
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
            'discount_price' => null,
            'duration_minutes' => 60,
            'is_published' => true,
        ]);
    }

    private function student(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'student')->value('id'));

        return $user;
    }
}
