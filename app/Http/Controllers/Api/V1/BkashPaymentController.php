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

class BkashPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly OrderService $orders,
    ) {}

    public function initiate(Request $request, Order $order): JsonResponse
    {
        $owned = $this->orders->findOwned($request->user(), (int) $order->id);
        $started = $this->payments->initiateBkash($request->user(), $owned);

        return ApiResponse::success([
            'payment' => PaymentResource::make($started['payment'])->resolve(),
            'gateway_url' => $started['gateway_url'],
        ], 'bKash payment session created.');
    }

    public function callback(Request $request): RedirectResponse
    {
        $paymentId = (string) $request->query('paymentID', $request->input('paymentID', ''));
        $status = (string) $request->query('status', $request->input('status', ''));

        try {
            $payment = $this->payments->handleBkashCallback($paymentId, $status);
        } catch (\Throwable) {
            return $this->frontendRedirect('failed', null);
        }

        $result = match ($payment->status) {
            Payment::PAID => 'success',
            Payment::CANCELLED => 'cancelled',
            default => 'failed',
        };

        return $this->frontendRedirect($result, $payment->order_id);
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
