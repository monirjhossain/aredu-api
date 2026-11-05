<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminCouponTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_admin_can_crud_coupons_and_used_coupon_cannot_be_deleted(): void
    {
        $admin = $this->userWithPermission('payments.manage');
        $token = $admin->createToken('api')->plainTextToken;

        $created = $this->withToken($token)
            ->postJson('/api/v1/admin/coupons', [
                'code' => 'save20',
                'type' => Coupon::PERCENT,
                'value' => 20,
                'max_uses' => 50,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'SAVE20')
            ->assertJsonPath('data.type', 'percent')
            ->assertJsonPath('data.value', '20.00')
            ->assertJsonPath('data.usages_count', 0);

        $id = $created->json('data.id');

        Auth::forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v1/admin/coupons?search=SAVE')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('meta.total', 1);

        Auth::forgetGuards();
        $this->withToken($token)
            ->putJson("/api/v1/admin/coupons/{$id}", [
                'code' => 'SAVE20',
                'type' => Coupon::FIXED,
                'value' => 300,
                'is_active' => false,
                'max_uses' => 10,
            ])
            ->assertOk()
            ->assertJsonPath('data.type', 'fixed')
            ->assertJsonPath('data.value', '300.00')
            ->assertJsonPath('data.is_active', false);

        $student = $this->userWithRole('student');
        $order = $this->pendingOrder($student);
        CouponUsage::query()->create([
            'coupon_id' => $id,
            'order_id' => $order->id,
            'user_id' => $student->id,
        ]);

        Auth::forgetGuards();
        $this->withToken($token)
            ->deleteJson("/api/v1/admin/coupons/{$id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['coupon']);

        Auth::forgetGuards();
        CouponUsage::query()->where('coupon_id', $id)->delete();
        $this->withToken($token)
            ->deleteJson("/api/v1/admin/coupons/{$id}")
            ->assertOk();

        $this->assertSame(0, Coupon::query()->count());
    }

    public function test_student_cannot_manage_coupons(): void
    {
        $student = $this->userWithRole('student');

        $this->withToken($student->createToken('api')->plainTextToken)
            ->getJson('/api/v1/admin/coupons')
            ->assertForbidden();
    }

    public function test_percent_over_100_is_rejected(): void
    {
        $admin = $this->userWithPermission('payments.manage');

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->postJson('/api/v1/admin/coupons', [
                'code' => 'BAD',
                'type' => Coupon::PERCENT,
                'value' => 150,
                'is_active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['value']);
    }

    private function pendingOrder(User $user): Order
    {
        $category = CourseCategory::query()->firstOrCreate(
            ['slug' => 'academic'],
            ['name' => 'Academic'],
        );
        $course = Course::query()->create([
            'category_id' => $category->id,
            'title' => 'Coupon Course',
            'slug' => 'coupon-course-'.uniqid(),
            'exam_type' => 'academic',
            'price' => 1000,
            'is_published' => true,
        ]);

        $order = Order::query()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-C-'.uniqid(),
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

        return $order;
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
