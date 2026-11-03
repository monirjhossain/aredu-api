<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Notification\UserNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function __construct(private readonly UserNotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->notifications->paginate($request->user());

        return ApiResponse::success(
            collect($page->items())->map(
                fn (DatabaseNotification $notification) => $this->notifications->transform($notification)
            )->values()->all(),
            'Notifications retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'unread_count' => $this->notifications->unreadCount($request->user()),
            ],
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success(
            ['unread_count' => $this->notifications->unreadCount($request->user())],
            'Unread notification count retrieved.',
        );
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $row = DatabaseNotification::query()->whereKey($notification)->firstOrFail();

        return ApiResponse::success(
            $this->notifications->transform(
                $this->notifications->markRead($request->user(), $row)
            ),
            'Notification marked as read.',
        );
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = $this->notifications->markAllRead($request->user());

        return ApiResponse::success(
            ['marked' => $count, 'unread_count' => 0],
            'All notifications marked as read.',
        );
    }
}
