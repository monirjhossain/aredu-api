<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FreePaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly OrderService $orders,
    ) {}

    /**
     * Settle a pending zero-total order (free course or 100% coupon).
     */
    public function settle(Request $request, Order $order): JsonResponse
    {
        $owned = $this->orders->findOwned($request->user(), (int) $order->id);
        $this->payments->settleZeroTotal($request->user(), $owned);

        return ApiResponse::success(
            OrderResource::make(
                $this->orders->findOwned($request->user(), (int) $order->id)
            )->resolve(),
            'Free order settled and enrollment activated.',
        );
    }
}
