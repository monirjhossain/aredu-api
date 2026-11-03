<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SslCommerzPaymentTest extends TestCase
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
            'app.url' => 'http://localhost',
            'app.frontend_url' => 'http://localhost:5173',
        ]);
    }

    public function test_student_can_initiate_sslcommerz_without_exposing_store_password(): void
    {
        Http::fake([
            'sandbox.sslcommerz.com/gwprocess/v4/api.php' => Http::response([
                'status' => 'SUCCESS',
                'sessionkey' => 'SESSION-1',
                'GatewayPageURL' => 'https://sandbox.sslcommerz.com/gwprocess/v4/gw.php?Q=PAY&SESSIONKEY=SESSION-1',
            ]),
        ]);

        $student = $this->student();
        $order = $this->pendingOrder($student, '1500.00');

        $response = $this->withToken($student->createToken('api')->plainTextToken)
            ->postJson('/api/v1/orders/'.$order->id.'/payments/sslcommerz')
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'initiated')
            ->assertJsonPath('data.payment.gateway', 'sslcommerz')
            ->assertJsonPath('data.payment.amount', '1500.00')
            ->assertJsonPath('data.gateway_url', 'https://sandbox.sslcommerz.com/gwprocess/v4/gw.php?Q=PAY&SESSIONKEY=SESSION-1');

        $response->assertDontSee('qwerty');
        $response->assertDontSee('store_passwd');

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame('SESSION-1', Payment::query()->value('gateway_reference'));
        $this->assertNotEmpty(Payment::query()->value('gateway_transaction_id'));
    }

    public function test_verified_ipn_marks_order_paid_and_is_idempotent(): void
    {
        $student = $this->student();
        $order = $this->pendingOrder($student, '1500.00');
        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => Payment::GATEWAY_SSLCOMMERZ,
            'status' => Payment::INITIATED,
            'amount' => '1500.00',
            'currency' => 'BDT',
            'gateway_transaction_id' => 'TRAN-SUCCESS-1',
        ]);

        Http::fake([
            'sandbox.sslcommerz.com/validator/api/validationserverAPI.php*' => Http::response([
                'status' => 'VALID',
                'tran_id' => 'TRAN-SUCCESS-1',
                'val_id' => 'VAL-1',
                'amount' => '1500.00',
                'currency_amount' => '1500.00',
                'currency_type' => 'BDT',
                'bank_tran_id' => 'BANK-1',
            ]),
        ]);

        $payload = [
            'status' => 'VALID',
            'tran_id' => 'TRAN-SUCCESS-1',
            'val_id' => 'VAL-1',
            'amount' => '1500.00',
            'currency' => 'BDT',
            'currency_type' => 'BDT',
            'currency_amount' => '1500.00',
        ];

        $this->post('/api/v1/payments/sslcommerz/ipn', $payload)
            ->assertOk()
            ->assertSee('IPN received');

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('BANK-1', $payment->fresh()->gateway_reference);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, Enrollment::query()->where('user_id', $student->id)->where('status', 'active')->count());

        $this->post('/api/v1/payments/sslcommerz/ipn', $payload)->assertOk();

        $this->assertSame(1, Payment::query()->where('status', 'paid')->count());
        $this->assertSame(1, Enrollment::query()->count());
    }

    public function test_browser_success_redirect_still_requires_gateway_validation(): void
    {
        $student = $this->student();
        $order = $this->pendingOrder($student, '1500.00');
        Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => Payment::GATEWAY_SSLCOMMERZ,
            'status' => Payment::INITIATED,
            'amount' => '1500.00',
            'currency' => 'BDT',
            'gateway_transaction_id' => 'TRAN-SUCCESS-2',
        ]);

        Http::fake([
            'sandbox.sslcommerz.com/validator/api/validationserverAPI.php*' => Http::response([
                'status' => 'INVALID_TRANSACTION',
                'tran_id' => 'TRAN-SUCCESS-2',
            ]),
        ]);

        $this->post('/api/v1/payments/sslcommerz/success', [
            'status' => 'VALID',
            'tran_id' => 'TRAN-SUCCESS-2',
            'val_id' => 'BAD-VAL',
            'amount' => '1500.00',
        ])->assertRedirect('http://localhost:5173/orders?payment=failed&order_id='.$order->id);

        $this->assertSame('failed', Payment::query()->value('status'));
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(0, Enrollment::query()->count());
    }

    public function test_fail_and_cancel_callbacks_do_not_enroll(): void
    {
        $student = $this->student();
        $failedOrder = $this->pendingOrder($student, '1200.00');
        Payment::query()->create([
            'order_id' => $failedOrder->id,
            'gateway' => Payment::GATEWAY_SSLCOMMERZ,
            'status' => Payment::INITIATED,
            'amount' => '1200.00',
            'currency' => 'BDT',
            'gateway_transaction_id' => 'TRAN-FAIL-1',
        ]);

        $this->post('/api/v1/payments/sslcommerz/fail', [
            'tran_id' => 'TRAN-FAIL-1',
            'status' => 'FAILED',
        ])->assertRedirect('http://localhost:5173/orders?payment=failed&order_id='.$failedOrder->id);

        $this->assertSame('failed', Payment::query()->where('gateway_transaction_id', 'TRAN-FAIL-1')->value('status'));
        $this->assertSame('failed', $failedOrder->fresh()->status);

        Auth::forgetGuards();

        $cancelOrder = $this->pendingOrder($student, '1100.00', slug: 'speaking');
        Payment::query()->create([
            'order_id' => $cancelOrder->id,
            'gateway' => Payment::GATEWAY_SSLCOMMERZ,
            'status' => Payment::INITIATED,
            'amount' => '1100.00',
            'currency' => 'BDT',
            'gateway_transaction_id' => 'TRAN-CANCEL-1',
        ]);

        $this->post('/api/v1/payments/sslcommerz/cancel', [
            'tran_id' => 'TRAN-CANCEL-1',
            'status' => 'CANCELLED',
        ])->assertRedirect('http://localhost:5173/orders?payment=cancelled&order_id='.$cancelOrder->id);

        $this->assertSame('cancelled', Payment::query()->where('gateway_transaction_id', 'TRAN-CANCEL-1')->value('status'));
        $this->assertSame('cancelled', $cancelOrder->fresh()->status);
        $this->assertSame(0, Enrollment::query()->count());
    }

    public function test_amount_mismatch_and_duplicate_paid_callbacks_are_safe(): void
    {
        $student = $this->student();
        $order = $this->pendingOrder($student, '1500.00');
        Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => Payment::GATEWAY_SSLCOMMERZ,
            'status' => Payment::INITIATED,
            'amount' => '1500.00',
            'currency' => 'BDT',
            'gateway_transaction_id' => 'TRAN-MISMATCH-1',
        ]);

        Http::fake([
            'sandbox.sslcommerz.com/validator/api/validationserverAPI.php*' => Http::response([
                'status' => 'VALID',
                'tran_id' => 'TRAN-MISMATCH-1',
                'val_id' => 'VAL-MISMATCH',
                'currency_amount' => '10.00',
                'currency_type' => 'BDT',
            ]),
        ]);

        $this->postJson('/api/v1/payments/sslcommerz/ipn', [
            'status' => 'VALID',
            'tran_id' => 'TRAN-MISMATCH-1',
            'val_id' => 'VAL-MISMATCH',
        ])->assertStatus(422);

        $this->assertSame('initiated', Payment::query()->value('status'));
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, Enrollment::query()->count());
    }

    private function pendingOrder(User $user, string $total, string $slug = 'writing'): Order
    {
        $category = CourseCategory::query()->firstOrCreate(
            ['slug' => 'academic'],
            ['name' => 'Academic IELTS'],
        );

        $course = Course::query()->create([
            'category_id' => $category->id,
            'title' => ucfirst($slug),
            'slug' => $slug,
            'exam_type' => 'academic',
            'price' => $total,
            'duration_minutes' => 60,
            'is_published' => true,
        ]);

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

        return $order;
    }

    private function student(): User
    {
        $user = User::factory()->create([
            'name' => 'Pay Student',
            'email' => 'pay.'.uniqid().'@areducation.test',
            'phone' => '01711111111',
        ]);
        $user->roles()->attach(Role::query()->where('slug', 'student')->value('id'));

        return $user;
    }
}
