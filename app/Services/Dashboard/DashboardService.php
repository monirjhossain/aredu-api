<?php

namespace App\Services\Dashboard;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\SpeakingRecording;
use App\Models\User;
use App\Models\WritingSubmission;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $roleCounts = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->whereIn('roles.slug', ['student', 'teacher'])
            ->groupBy('roles.slug')
            ->select('roles.slug', DB::raw('COUNT(DISTINCT role_user.user_id) as total'))
            ->pluck('total', 'slug');

        $writing = $this->pendingWriting();
        $speaking = $this->pendingSpeaking();
        $students = (int) ($roleCounts['student'] ?? 0);
        $teachers = (int) ($roleCounts['teacher'] ?? 0);

        return [
            'students' => $students,
            'teachers' => $teachers,
            'courses' => Course::query()->count(),
            'enrollments' => Enrollment::query()->where('status', 'active')->count(),
            'revenue' => $this->revenue(),
            'recent_orders' => $this->recentOrders(),
            'recent_registrations' => $this->recentRegistrations(),
            'pending_evaluations' => [
                'total' => $writing['total'] + $speaking['total'],
                'writing' => $writing['total'],
                'speaking' => $speaking['total'],
                'items' => $writing['items']
                    ->concat($speaking['items'])
                    ->sortByDesc('submitted_at')
                    ->take(8)
                    ->values()
                    ->all(),
            ],
            'charts' => [
                'registrations_daily' => $this->dailyStudentRegistrations(14),
                'enrollments_daily' => $this->dailyEnrollments(14),
                'revenue_monthly' => $this->monthlyRevenue(6),
                'order_status' => $this->orderStatusBreakdown(),
                'user_mix' => [
                    ['key' => 'students', 'label' => 'students', 'value' => $students],
                    ['key' => 'teachers', 'label' => 'teachers', 'value' => $teachers],
                ],
                'top_courses' => $this->topCoursesByEnrollment(5),
            ],
        ];
    }

    /**
     * @return list<array{currency: string, total: string}>
     */
    private function revenue(): array
    {
        return Order::query()
            ->where('status', 'paid')
            ->toBase()
            ->select('currency')
            ->selectRaw('SUM(total) as total')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(fn ($row) => [
                'currency' => $row->currency,
                'total' => number_format((float) $row->total, 2, '.', ''),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentOrders(): array
    {
        return Order::query()
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get(['id', 'user_id', 'order_number', 'status', 'total', 'currency', 'created_at'])
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'total' => number_format((float) $order->total, 2, '.', ''),
                'currency' => $order->currency,
                'created_at' => $order->created_at?->toIso8601String(),
                'user' => [
                    'id' => $order->user?->id,
                    'name' => $order->user?->name,
                ],
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentRegistrations(): array
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->where('slug', 'student'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get(['id', 'name', 'email', 'created_at'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array{date: string, label: string, value: int}>
     */
    private function dailyStudentRegistrations(int $days): array
    {
        $start = now()->subDays($days - 1)->startOfDay();

        $rows = User::query()
            ->whereHas('roles', fn ($query) => $query->where('slug', 'student'))
            ->where('created_at', '>=', $start)
            ->toBase()
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        return $this->fillDailySeries($start, $days, $rows);
    }

    /**
     * @return list<array{date: string, label: string, value: int}>
     */
    private function dailyEnrollments(int $days): array
    {
        $start = now()->subDays($days - 1)->startOfDay();

        $rows = Enrollment::query()
            ->where('created_at', '>=', $start)
            ->toBase()
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        return $this->fillDailySeries($start, $days, $rows);
    }

    /**
     * @param  Collection<string, int|string>  $rows
     * @return list<array{date: string, label: string, value: int}>
     */
    private function fillDailySeries(Carbon $start, int $days, Collection $rows): array
    {
        $series = [];
        $period = CarbonPeriod::create($start, now()->startOfDay());

        foreach ($period as $day) {
            $key = $day->toDateString();
            $series[] = [
                'date' => $key,
                'label' => $day->format('d M'),
                'value' => (int) ($rows[$key] ?? 0),
            ];
        }

        return array_slice($series, -$days);
    }

    /**
     * @return list<array{month: string, label: string, value: float}>
     */
    private function monthlyRevenue(int $months): array
    {
        $start = now()->subMonths($months - 1)->startOfMonth();

        $rows = Order::query()
            ->where('status', 'paid')
            ->where('created_at', '>=', $start)
            ->toBase()
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month_key")
            ->selectRaw('SUM(total) as total')
            ->groupBy('month_key')
            ->pluck('total', 'month_key');

        $series = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $month = now()->subMonths($i)->startOfMonth();
            $key = $month->format('Y-m');
            $series[] = [
                'month' => $key,
                'label' => $month->format('M'),
                'value' => round((float) ($rows[$key] ?? 0), 2),
            ];
        }

        return $series;
    }

    /**
     * @return list<array{key: string, value: int}>
     */
    private function orderStatusBreakdown(): array
    {
        return Order::query()
            ->toBase()
            ->select('status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->map(fn ($row) => [
                'key' => (string) $row->status,
                'value' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, title: string, value: int}>
     */
    private function topCoursesByEnrollment(int $limit): array
    {
        return Enrollment::query()
            ->where('status', 'active')
            ->toBase()
            ->select('course_id')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('course_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $course = Course::query()->find($row->course_id);

                return [
                    'id' => (int) $row->course_id,
                    'title' => $course?->title ?: 'Course #'.$row->course_id,
                    'value' => (int) $row->total,
                ];
            })
            ->all();
    }

    /**
     * @return array{total: int, items: Collection<int, array<string, mixed>>}
     */
    private function pendingWriting(): array
    {
        $query = WritingSubmission::query()
            ->where('status', 'submitted')
            ->whereDoesntHave('evaluation');

        $items = (clone $query)
            ->with(['user:id,name', 'task:id,title'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (WritingSubmission $submission) => [
                'type' => 'writing',
                'id' => $submission->id,
                'student_name' => $submission->user?->name,
                'title' => $submission->task?->title,
                'submitted_at' => $submission->submitted_at?->toIso8601String(),
            ]);

        return ['total' => $query->count(), 'items' => $items];
    }

    /**
     * @return array{total: int, items: Collection<int, array<string, mixed>>}
     */
    private function pendingSpeaking(): array
    {
        $query = SpeakingRecording::query()
            ->where('status', 'submitted')
            ->whereDoesntHave('evaluation');

        $items = (clone $query)
            ->with(['user:id,name', 'task:id,title'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (SpeakingRecording $recording) => [
                'type' => 'speaking',
                'id' => $recording->id,
                'student_name' => $recording->user?->name,
                'title' => $recording->task?->title,
                'submitted_at' => $recording->submitted_at?->toIso8601String(),
            ]);

        return ['total' => $query->count(), 'items' => $items];
    }
}
