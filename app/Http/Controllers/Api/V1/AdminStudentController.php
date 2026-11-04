<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Student\AdminStudentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminStudentController extends Controller
{
    public function __construct(private readonly AdminStudentService $students) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $page = $this->students->paginate([
            'search' => $request->query('search'),
            'per_page' => $request->query('per_page'),
        ]);

        $rows = collect($page->items())->map(fn (User $student) => [
            'id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            'phone' => $student->phone,
            'locale' => $student->locale,
            'created_at' => optional($student->created_at)?->toIso8601String(),
            'orders_count' => (int) ($student->orders_count ?? 0),
            'active_enrollments_count' => (int) ($student->active_enrollments_count ?? 0),
        ])->values()->all();

        return ApiResponse::success(
            $rows,
            'Students retrieved.',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    public function show(int $student): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $detail = $this->students->detail($student);

        return ApiResponse::success([
            'student' => $detail['student'],
            'stats' => $detail['stats'],
            'enrollments' => $detail['enrollments'],
            'orders' => OrderResource::collection($detail['orders'])->resolve(),
        ], 'Student retrieved.');
    }
}
