<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPaymentController extends Controller
{
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
}
