<?php

namespace App\Services\Order;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use App\Services\Payment\PaymentService;
use App\Services\Settings\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly ActivityLogService $activity,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @param  list<int>  $courseIds
     */
    public function create(User $user, array $courseIds, ?string $couponCode = null): Order
    {
        $ids = array_values(array_unique(array_map('intval', $courseIds)));

        if ($ids === []) {
            throw ValidationException::withMessages([
                'course_ids' => ['Select at least one course.'],
            ]);
        }

        $owned = DB::transaction(function () use ($user, $ids, $couponCode) {
            $courses = Course::query()
                ->whereIn('id', $ids)
                ->where('is_published', true)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($courses->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'course_ids' => ['One or more courses are unavailable.'],
                ]);
            }

            $this->assertNoActiveEnrollment($user->id, $ids);
            $this->assertNoPendingDuplicate($user->id, $ids);

            $lines = [];
            $subtotal = 0.0;

            foreach ($ids as $id) {
                /** @var Course $course */
                $course = $courses->get($id);
                $unit = $this->money($course->price);
                $discount = $course->discount_price !== null ? $this->money($course->discount_price) : null;

                // Paid courses: discount must be strictly below list price.
                // Free courses (unit 0): discount must be null or 0.
                if ($discount !== null && (($unit <= 0 && $discount > 0) || ($unit > 0 && $discount >= $unit))) {
                    throw ValidationException::withMessages([
                        'course_ids' => ['A course has an invalid discount price.'],
                    ]);
                }

                $lineTotal = $discount ?? $unit;
                $subtotal += $lineTotal;
                $lines[] = [
                    'course_id' => $course->id,
                    'unit_price' => number_format($unit, 2, '.', ''),
                    'discount_price' => $discount !== null ? number_format($discount, 2, '.', '') : null,
                ];
            }

            $subtotal = round($subtotal, 2);
            $coupon = $this->resolveCoupon($couponCode);
            $discountTotal = $this->couponDiscount($coupon, $subtotal);
            $total = round(max($subtotal - $discountTotal, 0), 2);

            $order = $this->insertOrder($user->id, $subtotal, $discountTotal, $total);

            foreach ($lines as $line) {
                OrderItem::query()->create([
                    'order_id' => $order->id,
                    ...$line,
                ]);
            }

            if ($coupon !== null) {
                CouponUsage::query()->create([
                    'coupon_id' => $coupon->id,
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                ]);
            }

            $created = $this->findOwned($user, $order->id);

            $this->activity->record(
                $user,
                'order.created',
                $created,
                'Order '.$created->order_number.' created for '.$created->total.' '.$created->currency.'.',
            );

            return $created;
        });

        // Free / 100% coupon: enroll immediately so the order is never stuck pending.
        if ((float) $owned->total <= 0.009) {
            $this->payments->settleZeroTotal($user, $owned);

            return $this->findOwned($user, $owned->id);
        }

        return $owned;
    }

    public function paginateFor(User $user): LengthAwarePaginator
    {
        return Order::query()
            ->with(['items.course:id,title,slug', 'payments'])
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate(12);
    }

    /**
     * @param  array{
     *   status?: string|null,
     *   search?: string|null,
     *   gateway?: string|null,
     *   date_from?: string|null,
     *   date_to?: string|null,
     *   per_page?: int|null
     * }  $filters
     */
    public function paginateForAdmin(array $filters = []): LengthAwarePaginator
    {
        $status = isset($filters['status']) && is_string($filters['status']) ? trim($filters['status']) : '';
        $search = isset($filters['search']) && is_string($filters['search']) ? trim($filters['search']) : '';
        $gateway = isset($filters['gateway']) && is_string($filters['gateway']) ? trim($filters['gateway']) : '';
        $dateFrom = isset($filters['date_from']) && is_string($filters['date_from']) ? trim($filters['date_from']) : '';
        $dateTo = isset($filters['date_to']) && is_string($filters['date_to']) ? trim($filters['date_to']) : '';
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;
        $perPage = max(5, min($perPage, 50));

        $allowedStatuses = [
            Order::PENDING,
            Order::PAID,
            Order::FAILED,
            Order::CANCELLED,
            Order::REFUNDED,
        ];

        $allowedGateways = ['bkash', 'sslcommerz'];

        return Order::query()
            ->with([
                'user:id,name,email,phone',
                'items.course:id,title,slug',
                'payments',
            ])
            ->when(
                $status !== '' && in_array($status, $allowedStatuses, true),
                fn ($query) => $query->where('status', $status),
            )
            ->when(
                $gateway !== '' && in_array($gateway, $allowedGateways, true),
                fn ($query) => $query->whereHas('payments', fn ($paymentQuery) => $paymentQuery->where('gateway', $gateway)),
            )
            ->when($dateFrom !== '', function ($query) use ($dateFrom) {
                try {
                    $query->whereDate('created_at', '>=', $dateFrom);
                } catch (\Throwable) {
                    // Ignore invalid date input.
                }
            })
            ->when($dateTo !== '', function ($query) use ($dateTo) {
                try {
                    $query->whereDate('created_at', '<=', $dateTo);
                } catch (\Throwable) {
                    // Ignore invalid date input.
                }
            })
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('order_number', 'like', $like)
                        ->orWhereHas('user', function ($userQuery) use ($like) {
                            $userQuery->where('name', 'like', $like)
                                ->orWhere('email', 'like', $like)
                                ->orWhere('phone', 'like', $like);
                        })
                        ->orWhereHas('items.course', function ($courseQuery) use ($like) {
                            $courseQuery->where('title', 'like', $like);
                        });
                });
            })
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findOwned(User $user, int $orderId): Order
    {
        $order = Order::query()
            ->with(['items.course:id,title,slug,exam_type', 'payments'])
            ->where('user_id', $user->id)
            ->where('id', $orderId)
            ->first();

        if ($order === null) {
            throw new AuthorizationException('This order is not available.');
        }

        return $order;
    }

    public function findForAdmin(int $orderId): Order
    {
        $order = Order::query()
            ->with([
                'user:id,name,email,phone',
                'items.course:id,title,slug',
                'payments',
            ])
            ->whereKey($orderId)
            ->first();

        if ($order === null) {
            throw new AuthorizationException('This order is not available.');
        }

        return $order;
    }

    public function cancelForAdmin(User $admin, Order $order): Order
    {
        if (! in_array($order->status, [Order::PENDING, Order::FAILED], true)) {
            throw ValidationException::withMessages([
                'order' => ['Only pending or failed orders can be cancelled.'],
            ]);
        }

        $order->status = Order::CANCELLED;
        $order->save();

        $this->activity->record(
            $admin,
            'order.cancelled',
            $order,
            'Admin cancelled order '.$order->order_number.'.',
        );

        return $this->findForAdmin($order->id);
    }

    /**
     * @param  list<int>  $courseIds
     */
    private function assertNoActiveEnrollment(int $userId, array $courseIds): void
    {
        $exists = Enrollment::query()
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->whereIn('course_id', $courseIds)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'course_ids' => ['You already have access to one of these courses.'],
            ]);
        }
    }

    /**
     * @param  list<int>  $courseIds
     */
    private function assertNoPendingDuplicate(int $userId, array $courseIds): void
    {
        $exists = OrderItem::query()
            ->whereIn('course_id', $courseIds)
            ->whereHas('order', function ($query) use ($userId) {
                $query->where('user_id', $userId)->where('status', Order::PENDING);
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'course_ids' => ['You already have a pending order for one of these courses.'],
            ]);
        }
    }

    private function resolveCoupon(?string $code): ?Coupon
    {
        $code = trim((string) $code);

        if ($code === '') {
            return null;
        }

        $coupon = Coupon::query()
            ->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])
            ->lockForUpdate()
            ->first();

        if ($coupon === null || ! $coupon->is_active) {
            throw ValidationException::withMessages([
                'coupon_code' => ['This coupon is not valid.'],
            ]);
        }

        if ($coupon->starts_at !== null && $coupon->starts_at->isFuture()) {
            throw ValidationException::withMessages([
                'coupon_code' => ['This coupon is not valid yet.'],
            ]);
        }

        if ($coupon->ends_at !== null && $coupon->ends_at->isPast()) {
            throw ValidationException::withMessages([
                'coupon_code' => ['This coupon has expired.'],
            ]);
        }

        if ($coupon->type === Coupon::PERCENT && (float) $coupon->value > 100) {
            throw ValidationException::withMessages([
                'coupon_code' => ['This coupon is misconfigured.'],
            ]);
        }

        if ($coupon->max_uses !== null) {
            $used = CouponUsage::query()->where('coupon_id', $coupon->id)->count();
            if ($used >= $coupon->max_uses) {
                throw ValidationException::withMessages([
                    'coupon_code' => ['This coupon has no remaining uses.'],
                ]);
            }
        }

        return $coupon;
    }

    private function couponDiscount(?Coupon $coupon, float $subtotal): float
    {
        if ($coupon === null || $subtotal <= 0) {
            return 0.0;
        }

        if ($coupon->type === Coupon::PERCENT) {
            return round(min($subtotal, $subtotal * ((float) $coupon->value / 100)), 2);
        }

        return round(min($subtotal, (float) $coupon->value), 2);
    }

    private function insertOrder(int $userId, float $subtotal, float $discountTotal, float $total): Order
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return Order::query()->create([
                    'user_id' => $userId,
                    'order_number' => $this->nextOrderNumber(),
                    'status' => Order::PENDING,
                    'subtotal' => number_format($subtotal, 2, '.', ''),
                    'discount_total' => number_format($discountTotal, 2, '.', ''),
                    'total' => number_format($total, 2, '.', ''),
                    'currency' => $this->settings->currency(),
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw ValidationException::withMessages([
            'order_number' => ['Could not allocate a unique order number.'],
        ]);
    }

    private function nextOrderNumber(): string
    {
        return 'ORD-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
