<?php

namespace App\Services\Student;

use App\Models\Enrollment;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AdminStudentService
{
    /**
     * @param  array{search?: string|null, per_page?: int|null}  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $search = isset($filters['search']) && is_string($filters['search']) ? trim($filters['search']) : '';
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;
        $perPage = max(5, min($perPage, 50));

        return User::query()
            ->whereHas('roles', fn ($query) => $query->where('slug', 'student'))
            ->with(['roles:id,slug'])
            ->withCount([
                'orders',
                'enrollments as active_enrollments_count' => fn ($query) => $query->where('status', Enrollment::ACTIVE),
            ])
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like);
                });
            })
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(int $studentId): array
    {
        $student = User::query()
            ->whereKey($studentId)
            ->whereHas('roles', fn ($query) => $query->where('slug', 'student'))
            ->with(['roles:id,slug'])
            ->first();

        if ($student === null) {
            throw new AuthorizationException('This student is not available.');
        }

        $enrollments = Enrollment::query()
            ->with(['course:id,title,slug,exam_type,thumbnail_path'])
            ->where('user_id', $student->id)
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (Enrollment $enrollment) => [
                'id' => $enrollment->id,
                'status' => $enrollment->status,
                'enrolled_at' => $enrollment->enrolled_at?->toIso8601String(),
                'course' => $enrollment->course ? [
                    'id' => $enrollment->course->id,
                    'title' => $enrollment->course->title,
                    'slug' => $enrollment->course->slug,
                    'exam_type' => $enrollment->course->exam_type,
                    'thumbnail_url' => $enrollment->course->thumbnail_path
                        ? url('storage/'.$enrollment->course->thumbnail_path)
                        : null,
                ] : null,
            ])
            ->values()
            ->all();

        $orders = Order::query()
            ->with(['items.course:id,title', 'payments'])
            ->where('user_id', $student->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return [
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'phone' => $student->phone,
                'locale' => $student->locale,
                'created_at' => $student->created_at?->toIso8601String(),
                'roles' => $student->roles->pluck('slug')->values()->all(),
            ],
            'stats' => [
                'orders' => Order::query()->where('user_id', $student->id)->count(),
                'active_enrollments' => Enrollment::query()
                    ->where('user_id', $student->id)
                    ->where('status', Enrollment::ACTIVE)
                    ->count(),
                'pending_orders' => Order::query()
                    ->where('user_id', $student->id)
                    ->where('status', Order::PENDING)
                    ->count(),
            ],
            'enrollments' => $enrollments,
            'orders' => $orders,
        ];
    }
}
