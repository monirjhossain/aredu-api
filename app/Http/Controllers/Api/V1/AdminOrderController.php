<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Order\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $page = $this->orders->paginateForAdmin([
            'status' => $request->query('status'),
            'search' => $request->query('search'),
            'gateway' => $request->query('gateway'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'per_page' => $request->query('per_page'),
        ]);

        return ApiResponse::success(
            OrderResource::collection($page)->resolve(),
            'Orders retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    public function show(Order $order): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        return ApiResponse::success(
            OrderResource::make($this->orders->findForAdmin($order->id))->resolve(),
            'Order retrieved.',
        );
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $updated = $this->orders->cancelForAdmin($request->user(), $order);

        return ApiResponse::success(
            OrderResource::make($updated)->resolve(),
            'Order cancelled.',
        );
    }
}
