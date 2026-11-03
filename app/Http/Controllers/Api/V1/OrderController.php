<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Services\Order\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->orders->paginateFor($request->user());

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

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orders->create(
            $request->user(),
            $request->validated('course_ids'),
            $request->validated('coupon_code'),
        );

        return ApiResponse::success(
            OrderResource::make($order)->resolve(),
            'Order created.',
            201,
        );
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $owned = $this->orders->findOwned($request->user(), (int) $order->id);

        return ApiResponse::success(
            OrderResource::make($owned)->resolve(),
            'Order retrieved.',
        );
    }
}
