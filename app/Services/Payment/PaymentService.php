<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentFailedNotification;
use App\Notifications\PaymentSuccessfulNotification;
use App\Services\Activity\ActivityLogService;
use App\Services\Enrollment\EnrollmentService;
use App\Services\Settings\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class PaymentService
{
    public function __construct(
        private readonly SslCommerzGateway $sslcommerz,
        private readonly BkashGateway $bkash,
        private readonly EnrollmentService $enrollments,
        private readonly ActivityLogService $activity,
        private readonly SettingService $settings,
    ) {}

    /**
     * @return array{payment: Payment, gateway_url: string}
     */
    public function initiateSslCommerz(User $user, Order $order): array
    {
        $order = $this->assertPayableOrder($user, $order);

        if ((float) $order->total <= 0.009) {
            throw ValidationException::withMessages([
                'order' => ['This order is free. Use free checkout instead of SSLCommerz.'],
            ]);
        }

        if ((float) $order->total < 10) {
            throw ValidationException::withMessages([
                'order' => ['SSLCommerz requires a minimum amount of 10 BDT.'],
            ]);
        }

        $payment = $this->createInitiatedPayment($order, Payment::GATEWAY_SSLCOMMERZ);
        $apiBase = rtrim((string) config('app.url'), '/').'/api/v1/payments/sslcommerz';

        $productNames = $order->items
            ->map(fn ($item) => $item->course?->title)
            ->filter()
            ->values()
            ->all();

        $started = $this->sslcommerz->initiate([
            'total_amount' => number_format((float) $order->total, 2, '.', ''),
            'currency' => $order->currency ?: 'BDT',
            'tran_id' => $payment->gateway_transaction_id,
            'success_url' => $apiBase.'/success',
            'fail_url' => $apiBase.'/fail',
            'cancel_url' => $apiBase.'/cancel',
            'ipn_url' => $apiBase.'/ipn',
            'cus_name' => $order->user?->name ?: 'Student',
            'cus_email' => $order->user?->email ?: 'student@example.com',
            'cus_add1' => 'Dhaka',
            'cus_city' => 'Dhaka',
            'cus_postcode' => '1000',
            'cus_country' => 'Bangladesh',
            'cus_phone' => $order->user?->phone ?: '01700000000',
            'shipping_method' => 'NO',
            'num_of_item' => max(1, $order->items->count()),
            'product_name' => $productNames !== [] ? implode(',', $productNames) : 'Course',
            'product_category' => 'education',
            'product_profile' => 'non-physical-goods',
            'value_a' => (string) $order->id,
            'value_b' => (string) $payment->id,
        ]);

        if ($started['session_key'] !== null) {
            $payment->gateway_reference = $started['session_key'];
            $payment->save();
        }

        return [
            'payment' => $payment->fresh(),
            'gateway_url' => $started['gateway_url'],
        ];
    }

    /**
     * @return array{payment: Payment, gateway_url: string}
     */
    public function initiateBkash(User $user, Order $order): array
    {
        $order = $this->assertPayableOrder($user, $order);

        if ((float) $order->total <= 0.009) {
            throw ValidationException::withMessages([
                'order' => ['This order is free. Use free checkout instead of bKash.'],
            ]);
        }

        $payment = $this->createInitiatedPayment($order, Payment::GATEWAY_BKASH);
        $callbackUrl = rtrim((string) config('app.url'), '/').'/api/v1/payments/bkash/callback';

        $invoice = $payment->gateway_transaction_id;
        $created = $this->bkash->createPayment([
            'amount' => number_format((float) $order->total, 2, '.', ''),
            'merchant_invoice_number' => $invoice,
            'callback_url' => $callbackUrl,
            'payer_reference' => $order->user?->phone ?: ('user'.$order->user_id),
        ]);

        // Keep bKash paymentID as the durable lookup key for callbacks and duplicates.
        $payment->gateway_transaction_id = $created['payment_id'];
        $payment->gateway_reference = $created['payment_id'];
        $payment->save();

        return [
            'payment' => $payment->fresh(),
            'gateway_url' => $created['gateway_url'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleSslCommerzNotification(array $payload): Payment
    {
        $tranId = trim((string) ($payload['tran_id'] ?? ''));
        $status = strtoupper(trim((string) ($payload['status'] ?? '')));

        if ($tranId === '') {
            throw ValidationException::withMessages([
                'tran_id' => ['The transaction id is required.'],
            ]);
        }

        $payment = $this->paymentByTranId($tranId, Payment::GATEWAY_SSLCOMMERZ);

        if ($payment->status === Payment::PAID) {
            return $payment;
        }

        if (in_array($status, ['FAILED', 'UNATTEMPTED', 'EXPIRED'], true)) {
            return $this->markFailed($payment);
        }

        if ($status === 'CANCELLED') {
            return $this->markCancelled($payment);
        }

        if (! in_array($status, ['VALID', 'VALIDATED'], true)) {
            return $payment;
        }

        $validationId = trim((string) ($payload['val_id'] ?? ''));

        if ($validationId === '') {
            throw ValidationException::withMessages([
                'val_id' => ['The validation id is required.'],
            ]);
        }

        $validated = $this->sslcommerz->validate($validationId);
        $validatedStatus = strtoupper((string) ($validated['status'] ?? ''));

        if (! in_array($validatedStatus, ['VALID', 'VALIDATED'], true)) {
            return $this->markFailed($payment);
        }

        if ((string) ($validated['tran_id'] ?? '') !== $tranId) {
            throw ValidationException::withMessages([
                'tran_id' => ['The validated transaction does not match.'],
            ]);
        }

        $this->assertAmountAndCurrency(
            $payment,
            (float) ($validated['currency_amount'] ?? $validated['amount'] ?? 0),
            strtoupper((string) ($validated['currency_type'] ?? $validated['currency'] ?? '')),
        );

        return $this->markPaid(
            $payment,
            $validationId,
            isset($validated['bank_tran_id']) ? (string) $validated['bank_tran_id'] : null,
        );
    }

    public function handleBkashCallback(string $paymentId, string $status): Payment
    {
        $paymentId = trim($paymentId);
        $status = strtolower(trim($status));

        if ($paymentId === '') {
            throw ValidationException::withMessages([
                'paymentID' => ['The payment id is required.'],
            ]);
        }

        $payment = Payment::query()
            ->where('gateway', Payment::GATEWAY_BKASH)
            ->where(function ($query) use ($paymentId) {
                $query->where('gateway_transaction_id', $paymentId)
                    ->orWhere('gateway_reference', $paymentId);
            })
            ->first();

        if ($payment === null) {
            throw new HttpException(404, 'Payment not found.');
        }

        if ($payment->status === Payment::PAID) {
            return $payment;
        }

        if (in_array($status, ['failure', 'failed'], true)) {
            return $this->markFailed($payment);
        }

        if (in_array($status, ['cancel', 'cancelled', 'canceled'], true)) {
            return $this->markCancelled($payment);
        }

        if ($status !== 'success') {
            return $payment;
        }

        return $this->completeBkashPayment($payment, $paymentId);
    }

    public function markFailedFromRedirect(string $tranId): Payment
    {
        $payment = $this->paymentByTranId($tranId, Payment::GATEWAY_SSLCOMMERZ);

        if ($payment->status === Payment::PAID) {
            return $payment;
        }

        return $this->markFailed($payment);
    }

    public function markCancelledFromRedirect(string $tranId): Payment
    {
        $payment = $this->paymentByTranId($tranId, Payment::GATEWAY_SSLCOMMERZ);

        if ($payment->status === Payment::PAID) {
            return $payment;
        }

        return $this->markCancelled($payment);
    }

    public function findOwnedPayment(User $user, Payment $payment): Payment
    {
        $payment->loadMissing('order');

        if ($payment->order === null || (int) $payment->order->user_id !== (int) $user->id) {
            throw new AuthorizationException('This payment is not available.');
        }

        return $payment;
    }

    private function completeBkashPayment(Payment $payment, string $paymentId): Payment
    {
        try {
            $result = $this->bkash->executePayment($paymentId);
        } catch (Throwable) {
            $result = $this->bkash->queryPayment($paymentId);
        }

        if (($result['transactionStatus'] ?? '') !== 'Completed') {
            $result = $this->bkash->queryPayment($paymentId);
        }

        if (($result['transactionStatus'] ?? '') !== 'Completed') {
            return $this->markFailed($payment);
        }

        $this->assertAmountAndCurrency(
            $payment,
            (float) ($result['amount'] ?? 0),
            strtoupper((string) ($result['currency'] ?? 'BDT')),
        );

        $trxId = isset($result['trxID']) ? (string) $result['trxID'] : $paymentId;

        return $this->markPaid($payment, $paymentId, $trxId);
    }

    private function assertPayableOrder(User $user, Order $order): Order
    {
        if ((int) $order->user_id !== (int) $user->id) {
            throw new AuthorizationException('This order is not available.');
        }

        if ($order->status !== Order::PENDING) {
            throw ValidationException::withMessages([
                'order' => ['Only a pending order can be paid.'],
            ]);
        }

        $order->loadMissing(['user', 'items.course']);

        return $order;
    }

    private function assertAmountAndCurrency(Payment $payment, float $amount, string $currency): void
    {
        if (abs($amount - (float) $payment->amount) > 0.009) {
            throw ValidationException::withMessages([
                'amount' => ['The paid amount does not match the order.'],
            ]);
        }

        if ($currency !== '' && $currency !== strtoupper((string) $payment->currency)) {
            throw ValidationException::withMessages([
                'currency' => ['The paid currency does not match the order.'],
            ]);
        }
    }

    private function createInitiatedPayment(Order $order, string $gateway): Payment
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return Payment::query()->create([
                    'order_id' => $order->id,
                    'gateway' => $gateway,
                    'status' => Payment::INITIATED,
                    'amount' => $order->total,
                    'currency' => $order->currency ?: 'BDT',
                    'gateway_transaction_id' => $this->nextTranId($order->id),
                    'gateway_reference' => null,
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw ValidationException::withMessages([
            'gateway_transaction_id' => ['Could not allocate a unique transaction id.'],
        ]);
    }

    private function nextTranId(int $orderId): string
    {
        $prefix = 'O'.$orderId.'T';
        $suffix = Str::upper(Str::random(max(6, 30 - strlen($prefix))));

        return substr($prefix.$suffix, 0, 30);
    }

    private function paymentByTranId(string $tranId, string $gateway): Payment
    {
        $payment = Payment::query()
            ->where('gateway', $gateway)
            ->where('gateway_transaction_id', trim($tranId))
            ->first();

        if ($payment === null) {
            throw new HttpException(404, 'Payment not found.');
        }

        return $payment;
    }

    private function markFailed(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status === Payment::PAID || $payment->status === Payment::FAILED) {
                return $payment;
            }

            $payment->status = Payment::FAILED;
            $payment->save();

            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            if ($order->status === Order::PENDING) {
                $order->status = Order::FAILED;
                $order->save();
            }

            $this->activity->record(
                Auth::user() instanceof User ? Auth::user() : $order->user,
                'payment.failed',
                $payment,
                'Payment failed for order '.$order->order_number.' (gateway '.$payment->gateway.').',
            );

            $order->loadMissing('user');
            $notify = $this->settings->notificationsEnabled('payment');
            DB::afterCommit(function () use ($payment, $order, $notify) {
                if ($notify && $order->user) {
                    $order->user->notify(new PaymentFailedNotification($payment->fresh(), $order->fresh()));
                }
            });

            return $payment;
        });
    }

    private function markCancelled(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status === Payment::PAID) {
                return $payment;
            }

            $payment->status = Payment::CANCELLED;
            $payment->save();

            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            if ($order->status === Order::PENDING) {
                $order->status = Order::CANCELLED;
                $order->save();
            }

            $this->activity->record(
                Auth::user() instanceof User ? Auth::user() : $order->user,
                'payment.cancelled',
                $payment,
                'Payment cancelled for order '.$order->order_number.' (gateway '.$payment->gateway.').',
            );

            return $payment;
        });
    }

    /**
     * Settle a zero-total pending order (free course or 100% coupon) without a gateway.
     */
    public function settleZeroTotal(User $user, Order $order): Payment
    {
        if ((int) $order->user_id !== (int) $user->id) {
            throw new AuthorizationException('This order is not available.');
        }

        if ((float) $order->total > 0.009) {
            throw ValidationException::withMessages([
                'order' => ['This order requires a payment gateway.'],
            ]);
        }

        if ($order->status === Order::PAID) {
            $existingPaid = Payment::query()
                ->where('order_id', $order->id)
                ->where('status', Payment::PAID)
                ->orderByDesc('id')
                ->first();

            if ($existingPaid !== null) {
                return $existingPaid;
            }
        }

        $order = $this->assertPayableOrder($user, $order);

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => Payment::GATEWAY_FREE,
            'status' => Payment::INITIATED,
            'amount' => $order->total,
            'currency' => $order->currency ?: 'BDT',
            'gateway_transaction_id' => 'free-'.$order->id.'-'.Str::lower(Str::random(8)),
            'gateway_reference' => null,
        ]);

        $this->activity->record(
            $user,
            'payment.free_settled',
            $payment,
            'Free checkout settled for order '.$order->order_number.'.',
        );

        return $this->markPaid($payment, 'FREE-'.$order->order_number, 'FREE');
    }

    /**
     * Manual bKash QR payment confirmation by admin (no gateway API).
     */
    public function confirmManualBkash(User $admin, Order $order, string $trxId): Payment
    {
        if ($order->status === Order::PAID) {
            throw ValidationException::withMessages([
                'order' => ['This order is already paid.'],
            ]);
        }

        if ($order->status !== Order::PENDING && $order->status !== Order::FAILED) {
            throw ValidationException::withMessages([
                'order' => ['Only pending orders can be confirmed for bKash QR payment.'],
            ]);
        }

        $reference = trim($trxId);
        if ($reference === '') {
            throw ValidationException::withMessages([
                'trx_id' => ['TrxID is required to confirm bKash payment.'],
            ]);
        }

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => Payment::GATEWAY_BKASH,
            'status' => Payment::INITIATED,
            'amount' => $order->total,
            'currency' => $order->currency ?: 'BDT',
            'gateway_transaction_id' => 'manual-'.$order->id.'-'.Str::lower(Str::random(8)),
            'gateway_reference' => null,
        ]);

        $this->activity->record(
            $admin,
            'payment.bkash_qr_confirmed',
            $payment,
            'Admin confirmed bKash QR payment for order '.$order->order_number.'.',
        );

        return $this->markPaid($payment, $reference, $reference);
    }

    /**
     * @return list<Order>
     */
    public function pendingOrdersForAdmin(): array
    {
        return Order::query()
            ->with(['user:id,name,email', 'items.course:id,title'])
            ->where('status', Order::PENDING)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->all();
    }

    private function markPaid(Payment $payment, string $validationId, ?string $bankTranId): Payment
    {
        return DB::transaction(function () use ($payment, $validationId, $bankTranId) {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status === Payment::PAID) {
                return $payment;
            }

            $order = Order::query()
                ->with(['items', 'user'])
                ->whereKey($payment->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status === Order::PAID) {
                $payment->status = Payment::PAID;
                $payment->gateway_reference = $bankTranId ?: $validationId;
                $payment->save();

                return $payment;
            }

            if ($order->status !== Order::PENDING && $order->status !== Order::FAILED && $order->status !== Order::CANCELLED) {
                throw ValidationException::withMessages([
                    'order' => ['This order cannot be marked paid.'],
                ]);
            }

            $payment->status = Payment::PAID;
            $payment->gateway_reference = $bankTranId ?: $validationId;
            $payment->save();

            $order->status = Order::PAID;
            $order->save();

            $this->enrollments->activateForPaidOrder($order);

            $this->activity->record(
                Auth::user() instanceof User ? Auth::user() : $order->user,
                'payment.paid',
                $payment,
                'Payment marked paid for order '.$order->order_number.' (gateway '.$payment->gateway.').',
            );

            $notify = $this->settings->notificationsEnabled('payment');
            DB::afterCommit(function () use ($payment, $order, $notify) {
                if ($notify && $order->user) {
                    $order->user->notify(new PaymentSuccessfulNotification($payment->fresh(), $order->fresh()));
                }
            });

            return $payment;
        });
    }
}
