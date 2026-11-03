<?php

namespace App\Services\Activity;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ActivityLogService
{
    public function record(
        ?User $user,
        string $action,
        ?Model $subject = null,
        ?string $description = null,
    ): ActivityLog {
        return ActivityLog::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'description' => $description,
        ]);
    }

    /**
     * @param  array{action?: string|null, user_id?: int|null, date_from?: string|null, date_to?: string|null, page?: int|null}  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $from = isset($filters['date_from']) && $filters['date_from'] !== null && $filters['date_from'] !== ''
            ? Carbon::parse($filters['date_from'])->startOfDay()
            : null;
        $to = isset($filters['date_to']) && $filters['date_to'] !== null && $filters['date_to'] !== ''
            ? Carbon::parse($filters['date_to'])->endOfDay()
            : null;

        return ActivityLog::query()
            ->with('user:id,name,email')
            ->when(
                isset($filters['action']) && is_string($filters['action']) && $filters['action'] !== '',
                fn ($query) => $query->where('action', $filters['action']),
            )
            ->when(
                isset($filters['user_id']) && $filters['user_id'] !== null && $filters['user_id'] !== '',
                fn ($query) => $query->where('user_id', (int) $filters['user_id']),
            )
            ->when($from !== null, fn ($query) => $query->where('created_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('created_at', '<=', $to))
            ->orderByDesc('id')
            ->paginate(20);
    }

    /**
     * @return array<string, mixed>
     */
    public function transform(ActivityLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'description' => $log->description,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'user' => $log->user === null ? null : [
                'id' => $log->user->id,
                'name' => $log->user->name,
                'email' => $log->user->email,
            ],
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
