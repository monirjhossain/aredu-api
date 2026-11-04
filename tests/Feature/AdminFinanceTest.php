<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFinanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_admin_can_view_finance_summary_with_income_and_expenses(): void
    {
        $admin = $this->userWithRole('super_admin');
        $student = $this->userWithRole('student');
        $order = $this->paidOrder($student, '1500.00');

        Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => Payment::GATEWAY_BKASH,
            'status' => Payment::PAID,
            'amount' => '1500.00',
            'currency' => 'BDT',
            'gateway_transaction_id' => 'trx-1',
            'gateway_reference' => 'BK-1',
        ]);

        Expense::query()->create([
            'title' => 'Office rent',
            'category' => Expense::CATEGORY_RENT,
            'amount' => '500.00',
            'currency' => 'BDT',
            'spent_on' => now()->toDateString(),
            'created_by' => $admin->id,
        ]);

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->getJson('/api/v1/admin/finance/summary?date_from='.now()->startOfMonth()->toDateString().'&date_to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.income_gross', '1500.00')
            ->assertJsonPath('data.expense_total', '500.00')
            ->assertJsonPath('data.profit', '1000.00');
    }

    public function test_admin_can_crud_expenses_and_list_income(): void
    {
        $admin = $this->userWithRole('super_admin');
        $student = $this->userWithRole('student');
        $order = $this->paidOrder($student, '900.00');
        Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => Payment::GATEWAY_BKASH,
            'status' => Payment::PAID,
            'amount' => '900.00',
            'currency' => 'BDT',
            'gateway_transaction_id' => 'trx-2',
        ]);

        $create = $this->withToken($admin->createToken('api')->plainTextToken)
            ->postJson('/api/v1/admin/finance/expenses', [
                'title' => 'Facebook ads',
                'category' => 'marketing',
                'amount' => 250,
                'spent_on' => now()->toDateString(),
                'payment_method' => 'bkash',
                'notes' => 'Lead campaign',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Facebook ads');

        $expenseId = $create->json('data.id');

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->putJson('/api/v1/admin/finance/expenses/'.$expenseId, [
                'title' => 'Facebook ads updated',
                'category' => 'marketing',
                'amount' => 300,
                'spent_on' => now()->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.amount', '300.00');

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->getJson('/api/v1/admin/finance/expenses?date_from='.now()->toDateString().'&date_to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->getJson('/api/v1/admin/finance/income?date_from='.now()->toDateString().'&date_to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.order.order_number', $order->order_number);

        $this->withToken($admin->createToken('api')->plainTextToken)
            ->deleteJson('/api/v1/admin/finance/expenses/'.$expenseId)
            ->assertOk();

        $this->assertDatabaseMissing('expenses', ['id' => $expenseId]);
    }

    public function test_student_cannot_access_finance(): void
    {
        $student = $this->userWithRole('student');

        $this->withToken($student->createToken('api')->plainTextToken)
            ->getJson('/api/v1/admin/finance/summary')
            ->assertForbidden();
    }

    private function paidOrder(User $user, string $total): Order
    {
        $category = CourseCategory::query()->firstOrCreate(
            ['slug' => 'academic'],
            ['name' => 'Academic IELTS'],
        );
        $course = Course::query()->create([
            'category_id' => $category->id,
            'title' => 'Writing',
            'slug' => 'writing-'.uniqid(),
            'exam_type' => 'academic',
            'price' => $total,
            'duration_minutes' => 60,
            'is_published' => true,
        ]);

        $order = Order::query()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-FIN-'.uniqid(),
            'status' => Order::PAID,
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

        return $order;
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create([
            'phone' => '017'.random_int(10000000, 99999999),
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('slug', $roleSlug)->value('id'));

        return $user;
    }
}
