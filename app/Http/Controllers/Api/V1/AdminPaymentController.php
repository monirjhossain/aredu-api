<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\ConfirmBkashPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $status = $request->query('status');

        $page = Payment::query()
            ->with(['order:id,order_number,user_id,status,total,currency'])
            ->when(is_string($status) && $status !== '', fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(20);

        return ApiResponse::success(
            $page->getCollection()->map(fn (Payment $payment) => [
                ...PaymentResource::make($payment)->resolve(),
                'order' => $payment->order ? [
                    'id' => $payment->order->id,
                    'order_number' => $payment->order->order_number,
                    'user_id' => $payment->order->user_id,
                    'status' => $payment->order->status,
                    'total' => $payment->order->total,
                    'currency' => $payment->order->currency,
                ] : null,
            ])->values()->all(),
            'Payments retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    public function pendingOrders(): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $orders = collect($this->payments->pendingOrdersForAdmin())->map(fn (Order $order) => [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'total' => $order->total,
            'currency' => $order->currency,
            'status' => $order->status,
            'created_at' => $order->created_at?->toIso8601String(),
            'user' => $order->user ? [
                'id' => $order->user->id,
                'name' => $order->user->name,
                'email' => $order->user->email,
            ] : null,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'course_title' => $item->course?->title,
            ])->values()->all(),
        ])->values()->all();

        return ApiResponse::success($orders, 'Pending orders retrieved.');
    }

    public function confirmBkash(ConfirmBkashPaymentRequest $request, Order $order): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $payment = $this->payments->confirmManualBkash(
            $request->user(),
            $order,
            $request->validated('trx_id'),
        );

        return ApiResponse::success(
            PaymentResource::make($payment->load('order'))->resolve(),
            'bKash payment confirmed. Enrollment activated.',
        );
    }
}
