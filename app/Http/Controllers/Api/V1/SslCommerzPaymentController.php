<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SslCommerzPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly OrderService $orders,
    ) {}

    public function initiate(Request $request, Order $order): JsonResponse
    {
        $owned = $this->orders->findOwned($request->user(), (int) $order->id);
        $started = $this->payments->initiateSslCommerz($request->user(), $owned);

        return ApiResponse::success([
            'payment' => PaymentResource::make($started['payment'])->resolve(),
            'gateway_url' => $started['gateway_url'],
        ], 'Payment session created.');
    }

    public function ipn(Request $request): Response
    {
        $this->payments->handleSslCommerzNotification($request->all());

        return response('IPN received', 200);
    }

    public function success(Request $request): RedirectResponse
    {
        try {
            $payment = $this->payments->handleSslCommerzNotification($request->all());
        } catch (\Throwable) {
            return $this->frontendRedirect('failed', null);
        }

        return $this->frontendRedirect(
            $payment->status === Payment::PAID ? 'success' : 'failed',
            $payment->order_id,
        );
    }

    public function fail(Request $request): RedirectResponse
    {
        $tranId = (string) $request->input('tran_id', '');
        $orderId = null;

        try {
            if ($tranId !== '') {
                $payment = $this->payments->markFailedFromRedirect($tranId);
                $orderId = $payment->order_id;
            }
        } catch (\Throwable) {
            // Still send the customer back to the app.
        }

        return $this->frontendRedirect('failed', $orderId);
    }

    public function cancel(Request $request): RedirectResponse
    {
        $tranId = (string) $request->input('tran_id', '');
        $orderId = null;

        try {
            if ($tranId !== '') {
                $payment = $this->payments->markCancelledFromRedirect($tranId);
                $orderId = $payment->order_id;
            }
        } catch (\Throwable) {
            // Still send the customer back to the app.
        }

        return $this->frontendRedirect('cancelled', $orderId);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $owned = $this->payments->findOwnedPayment($request->user(), $payment);

        return ApiResponse::success(
            PaymentResource::make($owned)->resolve(),
            'Payment retrieved.',
        );
    }

    private function frontendRedirect(string $status, ?int $orderId): RedirectResponse
    {
        $base = rtrim((string) config('app.frontend_url'), '/');
        $query = http_build_query(array_filter([
            'payment' => $status,
            'order_id' => $orderId,
        ], fn ($value) => $value !== null && $value !== ''));

        return redirect()->away($base.'/orders'.($query !== '' ? '?'.$query : ''));
    }
}
