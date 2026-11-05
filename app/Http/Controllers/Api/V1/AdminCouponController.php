<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Coupon\CouponRequest;
use App\Http\Resources\CouponResource;
use App\Http\Responses\ApiResponse;
use App\Models\Coupon;
use App\Services\Coupon\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCouponController extends Controller
{
    public function __construct(private readonly CouponService $coupons) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->coupons->paginate([
            'search' => $request->query('search'),
            'active' => $request->query('active'),
        ]);

        return ApiResponse::success(
            CouponResource::collection($page->getCollection())->resolve(),
            'Coupons retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    public function store(CouponRequest $request): JsonResponse
    {
        return ApiResponse::success(
            CouponResource::make(
                $this->coupons->create($request->user(), $request->validated())
            )->resolve(),
            'Coupon created.',
            201,
        );
    }

    public function show(Coupon $coupon): JsonResponse
    {
        return ApiResponse::success(
            CouponResource::make($this->coupons->find($coupon))->resolve(),
            'Coupon retrieved.',
        );
    }

    public function update(CouponRequest $request, Coupon $coupon): JsonResponse
    {
        return ApiResponse::success(
            CouponResource::make(
                $this->coupons->update($request->user(), $coupon, $request->validated())
            )->resolve(),
            'Coupon updated.',
        );
    }

    public function destroy(Request $request, Coupon $coupon): JsonResponse
    {
        $this->coupons->delete($request->user(), $coupon);

        return ApiResponse::success(null, 'Coupon deleted.');
    }
}
