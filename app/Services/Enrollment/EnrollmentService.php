<?php

namespace App\Services\Enrollment;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\EnrollmentActivatedNotification;
use App\Services\Activity\ActivityLogService;
use App\Services\Settings\SettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnrollmentService
{
    public function __construct(
        private readonly ActivityLogService $activity,
        private readonly SettingService $settings,
    ) {}

    public const ACTIVE = 'active';

    public const REFUNDED = 'refunded';

    public function activateForPaidOrder(Order $order): void
    {
        $order->loadMissing(['items', 'user']);

        foreach ($order->items as $item) {
            $enrollment = Enrollment::query()
                ->where('user_id', $order->user_id)
                ->where('course_id', $item->course_id)
                ->lockForUpdate()
                ->first();

            $wasActive = $enrollment !== null && $enrollment->status === self::ACTIVE;

            if ($enrollment === null) {
                $enrollment = Enrollment::query()->create([
                    'user_id' => $order->user_id,
                    'course_id' => $item->course_id,
                    'order_id' => $order->id,
                    'status' => self::ACTIVE,
                    'enrolled_at' => now(),
                ]);
            } else {
                $enrollment->status = self::ACTIVE;
                $enrollment->order_id = $order->id;
                $enrollment->enrolled_at = $enrollment->enrolled_at ?? now();
                $enrollment->save();
            }

            if ($wasActive) {
                continue;
            }

            $course = Course::query()->whereKey($item->course_id)->first();
            if ($course === null || $order->user === null) {
                continue;
            }

            $this->activity->record(
                $order->user,
                'enrollment.activated',
                $enrollment,
                'Enrollment activated for course #'.$item->course_id.' (order '.$order->order_number.').',
            );

            $user = $order->user;
            $enrollmentId = $enrollment->id;
            $courseId = $course->id;
            $notify = $this->settings->notificationsEnabled('enrollment');
            DB::afterCommit(function () use ($user, $enrollmentId, $courseId, $notify) {
                if (! $notify) {
                    return;
                }
                $enrollment = Enrollment::query()->find($enrollmentId);
                $course = Course::query()->find($courseId);
                if ($enrollment && $course) {
                    $user->notify(new EnrollmentActivatedNotification($enrollment, $course));
                }
            });
        }
    }

    public function refundPayment(Payment $payment): Payment
    {
        if ($payment->status !== Payment::PAID) {
            throw ValidationException::withMessages([
                'payment' => ['Only a paid payment can be refunded.'],
            ]);
        }

        return DB::transaction(function () use ($payment) {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status === Payment::REFUNDED) {
                return $payment;
            }

            if ($payment->status !== Payment::PAID) {
                throw ValidationException::withMessages([
                    'payment' => ['Only a paid payment can be refunded.'],
                ]);
            }

            $order = Order::query()
                ->with('items')
                ->whereKey($payment->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $payment->status = Payment::REFUNDED;
            $payment->save();

            $order->status = Order::REFUNDED;
            $order->save();

            $courseIds = $order->items->pluck('course_id')->all();

            Enrollment::query()
                ->where('user_id', $order->user_id)
                ->whereIn('course_id', $courseIds)
                ->where('status', self::ACTIVE)
                ->lockForUpdate()
                ->get()
                ->each(function (Enrollment $enrollment) {
                    $enrollment->status = self::REFUNDED;
                    $enrollment->save();

                    $this->activity->record(
                        Auth::user(),
                        'enrollment.refunded',
                        $enrollment,
                        'Enrollment refunded for course #'.$enrollment->course_id.'.',
                    );
                });

            $this->activity->record(
                Auth::user(),
                'payment.refunded',
                $payment,
                'Payment refunded for order #'.$order->order_number.' (gateway '.$payment->gateway.').',
            );

            return $payment->fresh();
        });
    }

    public function paginateForStudent(User $user): LengthAwarePaginator
    {
        return Enrollment::query()
            ->with(['course.category:id,name'])
            ->where('user_id', $user->id)
            ->where('status', self::ACTIVE)
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
            ->paginate(12);
    }

    public function learningCourse(User $user, string $slug): Course
    {
        $course = Course::query()
            ->with([
                'category:id,name',
                'teachers:id,name',
                'sections' => function ($query) {
                    $query->whereHas('lessons', fn ($lessons) => $lessons->where('is_published', true))
                        ->orderBy('position')
                        ->orderBy('id');
                },
                'sections.lessons' => function ($query) {
                    $query->where('is_published', true)
                        ->orderBy('position')
                        ->orderBy('id');
                },
            ])
            ->where('slug', $slug)
            ->where('is_published', true)
            ->first();

        if ($course === null) {
            throw (new ModelNotFoundException)->setModel(Course::class, [$slug]);
        }

        if (! $this->hasActiveAccess($user->id, (int) $course->id)) {
            throw new AuthorizationException('An active enrollment is required to open this course.');
        }

        $course->setAttribute('viewer_enrolled', true);

        return $course;
    }

    public function hasActiveAccess(int $userId, int $courseId): bool
    {
        return Enrollment::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('status', self::ACTIVE)
            ->exists();
    }
}
