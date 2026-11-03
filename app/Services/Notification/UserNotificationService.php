<?php

namespace App\Services\Notification;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;

class UserNotificationService
{
    public function paginate(User $user): LengthAwarePaginator
    {
        return $user->notifications()->paginate(20);
    }

    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    public function markRead(User $user, DatabaseNotification $notification): DatabaseNotification
    {
        if ((string) $notification->notifiable_id !== (string) $user->id
            || $notification->notifiable_type !== $user->getMorphClass()) {
            throw new AuthorizationException('You can only update your own notifications.');
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return $notification->fresh();
    }

    public function markAllRead(User $user): int
    {
        $count = $user->unreadNotifications()->count();
        $user->unreadNotifications()->update(['read_at' => now()]);

        return $count;
    }

    /**
     * @param  DatabaseNotification  $notification
     * @return array<string, mixed>
     */
    public function transform(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'type' => class_basename($notification->type),
            'event' => $data['event'] ?? null,
            'title' => $data['title'] ?? class_basename($notification->type),
            'message' => $data['message'] ?? ($data['title'] ?? ''),
            'url' => $data['url'] ?? null,
            'data' => $data,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
