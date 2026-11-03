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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BkashPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        Cache::flush();
        config([
            'services.bkash.username' => 'sandboxUser',
            'services.bkash.password' => 'sandboxPass',
            'services.bkash.app_key' => 'app-key-secret',
            'services.bkash.app_secret' => 'app-secret-value',
            'services.bkash.sandbox' => true,
            'app.url' => 'http://localhost',
            'app.frontend_url' => 'http://localhost:5173',
        ]);
    }

    public function test_student_can_initiate_bkash_without_exposing_secrets(): void
    {
        Http::fake([
            'tokenized.sandbox.bka.sh/*/token/grant' => Http::response([
                'statusCode' => '0000',
                'statusMessage' => 'Successful',
                'id_token' => 'token-abc',
                'expires_in' => '3600',
            ]),
            'tokenized.sandbox.bka.sh/*/checkout/create' => Http::response([
                'statusCode' => '0000',
                'statusMessage' => 'Successful',
                'paymentID' => 'PAY-BKASH-1',
                'bkashURL' => 'https://sandbox.payment.bkash.com/redirect/tokenized/?paymentID=PAY-BKASH-1',
                'transactionStatus' => 'Initiated',
            ]),
        ]);

        $student = $this->student();
        $order = $this->pendingOrder($student, '1500.00');

        $response = $this->withToken($student->createToken('api')->plainTextToken)
            ->postJson('/api/v1/orders/'.$order->id.'/payments/bkash')
            ->assertOk()
            ->assertJsonPath('data.payment.gateway', 'bkash')
            ->assertJsonPath('data.payment.status', 'initiated')
            ->assertJsonPath('data.payment.gateway_transaction_id', 'PAY-BKASH-1')
            ->assertJsonPath('data.gateway_url', 'https://sandbox.payment.bkash.com/redirect/tokenized/?paymentID=PAY-BKASH-1');

        $response->assertDontSee('sandboxPass');
        $response->assertDontSee('app-secret-value');
        $response->assertDontSee('token-abc');
    }

    public function test_successful_callback_executes_and_is_idempotent(): void
    {
        $student = $this->student();
        $order = $this->pendingOrder($student, '1500.00');
        Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => Payment::GATEWAY_BKASH,
            'status' => Payment::INITIATED,
            'amount' => '1500.00',
            'currency' => 'BDT',
            'gateway_transaction_id' => 'PAY-BKASH-2',
            'gateway_reference' => 'PAY-BKASH-2',
        ]);

        Http::fake([
            'tokenized.sandbox.bka.sh/*/token/grant' => Http::response([
                'statusCode' => '0000',
                'id_token' => 'token-abc',
                'expires_in' => '3600',
            ]),
            'tokenized.sandbox.bka.sh/*/checkout/execute' => Http::response([
                'statusCode' => '0000',
                'statusMessage' => 'Successful',
                'paymentID' => 'PAY-BKASH-2',
                'trxID' => 'TRX-99',
                'transactionStatus' => 'Completed',
                'amount' => '1500.00',
                'currency' => 'BDT',
            ]),
        ]);

        $this->get('/api/v1/payments/bkash/callback?paymentID=PAY-BKASH-2&status=success')
            ->assertRedirect('http://localhost:5173/orders?payment=success&order_id='.$order->id);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('paid', Payment::query()->value('status'));
        $this->assertSame('TRX-99', Payment::query()->value('gateway_reference'));
        $this->assertSame(1, Enrollment::query()->where('status', 'active')->count());

        $this->get('/api/v1/payments/bkash/callback?paymentID=PAY-BKASH-2&status=success')
            ->assertRedirect('http://localhost:5173/orders?payment=success&order_id='.$order->id);

        $this->assertSame(1, Enrollment::query()->count());
        $this->assertSame(1, Payment::query()->where('status', 'paid')->count());
    }

    public function test_failure_and_cancel_callbacks_do_not_enroll(): void
    {
        $student = $this->student();
        $failed = $this->pendingOrder($student, '1200.00');
        Payment::query()->create([
            'order_id' => $failed->id,
            'gateway' => Payment::GATEWAY_BKASH,
            'status' => Payment::INITIATED,
            'amount' => '1200.00',
            'currency' => 'BDT',
            'gateway_transaction_id' => 'PAY-FAIL',
            'gateway_reference' => 'PAY-FAIL',
        ]);

        $this->get('/api/v1/payments/bkash/callback?paymentID=PAY-FAIL&status=failure')
            ->assertRedirect('http://localhost:5173/orders?payment=failed&order_id='.$failed->id);

        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertSame(0, Enrollment::query()->count());

        $cancelled = $this->pendingOrder($student, '1100.00', 'speaking');
        Payment::query()->create([
            'order_id' => $cancelled->id,
            'gateway' => Payment::GATEWAY_BKASH,
            'status' => Payment::INITIATED,
            'amount' => '1100.00',
            'currency' => 'BDT',
            'gateway_transaction_id' => 'PAY-CANCEL',
            'gateway_reference' => 'PAY-CANCEL',
        ]);

        $this->get('/api/v1/payments/bkash/callback?paymentID=PAY-CANCEL&status=cancel')
            ->assertRedirect('http://localhost:5173/orders?payment=cancelled&order_id='.$cancelled->id);

        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertSame(0, Enrollment::query()->count());
    }

    public function test_query_is_used_when_execute_fails_and_amount_mismatch_is_rejected(): void
    {
        $student = $this->student();
        $order = $this->pendingOrder($student, '1500.00');
        Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => Payment::GATEWAY_BKASH,
            'status' => Payment::INITIATED,
            'amount' => '1500.00',
            'currency' => 'BDT',
            'gateway_transaction_id' => 'PAY-QUERY',
            'gateway_reference' => 'PAY-QUERY',
        ]);

        Http::fake([
            'tokenized.sandbox.bka.sh/*/token/grant' => Http::response([
                'statusCode' => '0000',
                'id_token' => 'token-abc',
                'expires_in' => '3600',
            ]),
            'tokenized.sandbox.bka.sh/*/checkout/execute' => Http::response(['statusCode' => '2029', 'statusMessage' => 'timeout'], 500),
            'tokenized.sandbox.bka.sh/*/payment/status' => Http::response([
                'statusCode' => '0000',
                'paymentID' => 'PAY-QUERY',
                'trxID' => 'TRX-QUERY',
                'transactionStatus' => 'Completed',
                'amount' => '10.00',
                'currency' => 'BDT',
            ]),
        ]);

        $this->get('/api/v1/payments/bkash/callback?paymentID=PAY-QUERY&status=success')
            ->assertRedirect('http://localhost:5173/orders?payment=failed');

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
            'phone' => '01711111111',
        ]);
        $user->roles()->attach(Role::query()->where('slug', 'student')->value('id'));

        return $user;
    }
}
