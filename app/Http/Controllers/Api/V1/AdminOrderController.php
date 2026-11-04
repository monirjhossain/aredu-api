<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
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
}
