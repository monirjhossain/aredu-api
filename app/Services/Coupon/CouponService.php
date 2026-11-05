<?php

namespace App\Services\Coupon;

use App\Models\Coupon;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class CouponService
{
    public function __construct(private readonly ActivityLogService $activity) {}

    /**
     * @param  array{search?: string|null, active?: string|null}  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $search = $this->term($filters['search'] ?? null);
        $active = isset($filters['active']) ? trim((string) $filters['active']) : '';

        return Coupon::query()
            ->withCount('usages')
            ->when($search !== null, function ($query) use ($search) {
                $query->where('code', 'like', $search);
            })
            ->when($active === '1' || $active === 'true', fn ($query) => $query->where('is_active', true))
            ->when($active === '0' || $active === 'false', fn ($query) => $query->where('is_active', false))
            ->orderByDesc('id')
            ->paginate(15);
    }

    public function find(Coupon $coupon): Coupon
    {
        return Coupon::query()->withCount('usages')->findOrFail($coupon->id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): Coupon
    {
        $payload = $this->normalized($data);

        $coupon = Coupon::query()->create($payload);

        $this->activity->record(
            $actor,
            'coupon.created',
            $coupon,
            'Coupon '.$coupon->code.' created.',
        );

        return $this->find($coupon);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Coupon $coupon, array $data): Coupon
    {
        $payload = $this->normalized($data, $coupon->id);
        $coupon->fill($payload)->save();

        $this->activity->record(
            $actor,
            'coupon.updated',
            $coupon,
            'Coupon '.$coupon->code.' updated.',
        );

        return $this->find($coupon);
    }

    public function delete(User $actor, Coupon $coupon): void
    {
        if ($coupon->usages()->exists()) {
            throw ValidationException::withMessages([
                'coupon' => ['This coupon has already been used. Deactivate it instead of deleting.'],
            ]);
        }

        $code = $coupon->code;
        $coupon->delete();

        $this->activity->record(
            $actor,
            'coupon.deleted',
            null,
            'Coupon '.$code.' deleted.',
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalized(array $data, ?int $ignoreId = null): array
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));

        $exists = Coupon::query()
            ->whereRaw('UPPER(code) = ?', [$code])
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => ['This coupon code is already in use.'],
            ]);
        }

        $type = (string) ($data['type'] ?? '');
        $value = round((float) ($data['value'] ?? 0), 2);

        if ($type === Coupon::PERCENT && $value > 100) {
            throw ValidationException::withMessages([
                'value' => ['Percent coupons cannot exceed 100.'],
            ]);
        }

        if ($value <= 0) {
            throw ValidationException::withMessages([
                'value' => ['Coupon value must be greater than zero.'],
            ]);
        }

        $startsAt = $data['starts_at'] ?? null;
        $endsAt = $data['ends_at'] ?? null;

        if ($startsAt && $endsAt && strtotime((string) $endsAt) < strtotime((string) $startsAt)) {
            throw ValidationException::withMessages([
                'ends_at' => ['End date must be on or after the start date.'],
            ]);
        }

        $maxUses = $data['max_uses'] ?? null;

        return [
            'code' => $code,
            'type' => $type,
            'value' => number_format($value, 2, '.', ''),
            'starts_at' => $startsAt ?: null,
            'ends_at' => $endsAt ?: null,
            'max_uses' => $maxUses === null || $maxUses === '' ? null : (int) $maxUses,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function term(?string $search): ?string
    {
        $search = trim((string) $search);

        if ($search === '') {
            return null;
        }

        return '%'.addcslashes($search, '%_\\').'%';
    }
}
