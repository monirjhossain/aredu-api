<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_guest_cannot_create_or_list_orders(): void
    {
        $course = $this->course('writing', '1000.00', '800.00');

        $this->postJson('/api/v1/orders', ['course_ids' => [$course->id]])
            ->assertUnauthorized();

        $this->getJson('/api/v1/orders')->assertUnauthorized();
    }

    public function test_student_order_uses_server_prices_and_ignores_client_amounts(): void
    {
        $course = $this->course('writing', '2000.00', '1500.00');
        $student = $this->student();
        $token = $student->createToken('api')->plainTextToken;

        $created = $this->withToken($token)
            ->postJson('/api/v1/orders', [
                'course_ids' => [$course->id],
                'total' => '1.00',
                'subtotal' => '1.00',
                'unit_price' => '1.00',
                'price' => '1.00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.subtotal', '1500.00')
            ->assertJsonPath('data.discount_total', '0.00')
            ->assertJsonPath('data.total', '1500.00')
            ->assertJsonPath('data.currency', 'BDT')
            ->assertJsonPath('data.items.0.unit_price', '2000.00')
            ->assertJsonPath('data.items.0.discount_price', '1500.00')
            ->assertJsonPath('data.items.0.line_total', '1500.00');

        $this->assertNotEmpty($created->json('data.order_number'));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, OrderItem::query()->count());
    }

    public function test_coupon_discount_and_duplicate_pending_orders_are_blocked(): void
    {
        $first = $this->course('writing', '1000.00', null);
        $second = $this->course('speaking', '500.00', '400.00');
        $student = $this->student();
        $token = $student->createToken('api')->plainTextToken;

        Coupon::query()->create([
            'code' => 'SAVE10',
            'type' => 'percent',
            'value' => 10,
            'is_active' => true,
            'max_uses' => 5,
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/orders', [
                'course_ids' => [$first->id, $second->id],
                'coupon_code' => 'save10',
            ])
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '1400.00')
            ->assertJsonPath('data.discount_total', '140.00')
            ->assertJsonPath('data.total', '1260.00');

        Auth::forgetGuards();

        $this->withToken($token)
            ->postJson('/api/v1/orders', [
                'course_ids' => [$first->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['course_ids']);
    }

    public function test_order_history_is_owned_and_active_enrollment_blocks_purchase(): void
    {
        $course = $this->course('writing', '1000.00', null);
        $otherCourse = $this->course('listening', '700.00', null);
        $student = $this->student();
        $other = $this->student();
        $token = $student->createToken('api')->plainTextToken;

        $order = $this->withToken($token)
            ->postJson('/api/v1/orders', ['course_ids' => [$course->id]])
            ->assertCreated()
            ->json('data');

        Auth::forgetGuards();

        $this->withToken($other->createToken('api')->plainTextToken)
            ->getJson('/api/v1/orders/'.$order['id'])
            ->assertForbidden();

        Auth::forgetGuards();

        $this->withToken($token)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data.0.id', $order['id'])
            ->assertJsonPath('meta.total', 1);

        Order::query()->where('id', $order['id'])->update(['status' => Order::CANCELLED]);

        Enrollment::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        Auth::forgetGuards();

        $this->withToken($token)
            ->postJson('/api/v1/orders', ['course_ids' => [$course->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['course_ids']);

        Auth::forgetGuards();

        $this->withToken($token)
            ->postJson('/api/v1/orders', ['course_ids' => [$otherCourse->id]])
            ->assertCreated()
            ->assertJsonPath('data.total', '700.00');
    }

    public function test_unpublished_course_cannot_be_ordered(): void
    {
        $course = $this->course('draft', '1000.00', null, published: false);
        $student = $this->student();

        $this->withToken($student->createToken('api')->plainTextToken)
            ->postJson('/api/v1/orders', ['course_ids' => [$course->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['course_ids']);
    }

    private function course(string $slug, string $price, ?string $discount, bool $published = true): Course
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
            'discount_price' => $discount,
            'duration_minutes' => 60,
            'is_published' => $published,
        ]);
    }

    private function student(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', 'student')->value('id'));

        return $user;
    }
}
