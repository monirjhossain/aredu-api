<?php

namespace App\Services\Report;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\MockTest;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\SpeakingEvaluation;
use App\Models\TestAttempt;
use App\Models\User;
use App\Models\WritingEvaluation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportService
{
    public const TYPE_STUDENT_REGISTRATION = 'student_registration';

    public const TYPE_COURSE_SALES = 'course_sales';

    public const TYPE_REVENUE = 'revenue';

    public const TYPE_ENROLLMENT = 'enrollment';

    public const TYPE_PAYMENT_STATUS = 'payment_status';

    public const TYPE_COURSE_COMPLETION = 'course_completion';

    public const TYPE_READING_PERFORMANCE = 'reading_performance';

    public const TYPE_LISTENING_PERFORMANCE = 'listening_performance';

    public const TYPE_WRITING_EVALUATION = 'writing_evaluation';

    public const TYPE_SPEAKING_EVALUATION = 'speaking_evaluation';

    public const TYPES = [
        self::TYPE_STUDENT_REGISTRATION,
        self::TYPE_COURSE_SALES,
        self::TYPE_REVENUE,
        self::TYPE_ENROLLMENT,
        self::TYPE_PAYMENT_STATUS,
        self::TYPE_COURSE_COMPLETION,
        self::TYPE_READING_PERFORMANCE,
        self::TYPE_LISTENING_PERFORMANCE,
        self::TYPE_WRITING_EVALUATION,
        self::TYPE_SPEAKING_EVALUATION,
    ];

    private const ADMIN_ONLY = [
        self::TYPE_STUDENT_REGISTRATION,
        self::TYPE_COURSE_SALES,
        self::TYPE_REVENUE,
        self::TYPE_PAYMENT_STATUS,
    ];

    /** Teacher (`progress.view_assigned`) may only open course-scoped progress reports. */
    private const TEACHER_ALLOWED = [
        self::TYPE_ENROLLMENT,
        self::TYPE_COURSE_COMPLETION,
    ];

    /**
     * @return array{reports: list<array{key: string, label: string, admin_only: bool}>, courses: list<array{id: int, title: string}>}
     */
    public function availableFor(User $user): array
    {
        $isAdmin = $user->hasPermission('reports.view');
        $isTeacher = $user->hasPermission('progress.view_assigned');

        if (! $isAdmin && ! $isTeacher) {
            throw new AuthorizationException('You cannot view reports.');
        }

        $labels = [
            self::TYPE_STUDENT_REGISTRATION => 'Student registration',
            self::TYPE_COURSE_SALES => 'Course sales',
            self::TYPE_REVENUE => 'Revenue',
            self::TYPE_ENROLLMENT => 'Enrollment',
            self::TYPE_PAYMENT_STATUS => 'Payment status',
            self::TYPE_COURSE_COMPLETION => 'Course completion',
            self::TYPE_READING_PERFORMANCE => 'Reading performance',
            self::TYPE_LISTENING_PERFORMANCE => 'Listening performance',
            self::TYPE_WRITING_EVALUATION => 'Writing evaluation',
            self::TYPE_SPEAKING_EVALUATION => 'Speaking evaluation',
        ];

        $list = [];
        foreach (self::TYPES as $type) {
            $adminOnly = in_array($type, self::ADMIN_ONLY, true);
            if ($adminOnly && ! $isAdmin) {
                continue;
            }
            if (! $isAdmin && ! in_array($type, self::TEACHER_ALLOWED, true)) {
                continue;
            }
            $list[] = [
                'key' => $type,
                'label' => $labels[$type],
                'admin_only' => $adminOnly,
            ];
        }

        $coursesQuery = Course::query()->orderBy('title')->orderBy('id');
        if (! $isAdmin) {
            $assigned = DB::table('course_teachers')->where('user_id', $user->id)->pluck('course_id');
            $coursesQuery->whereIn('id', $assigned);
        }

        return [
            'reports' => $list,
            'courses' => $coursesQuery->get(['id', 'title'])->map(fn (Course $course) => [
                'id' => $course->id,
                'title' => $course->title,
            ])->all(),
        ];
    }

    /**
     * @param  array{date_from?: string|null, date_to?: string|null, course_id?: int|null, student_id?: int|null, per_page?: int|null}  $filters
     * @return array{columns: list<array{key: string, label: string}>, rows: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function paginate(User $user, string $type, array $filters): array
    {
        $this->assertCanAccess($user, $type);
        $page = $this->build($user, $type, $filters)->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'columns' => $this->columns($type),
            'rows' => collect($page->items())->map(fn ($row) => (array) $row)->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'report' => $type,
            ],
        ];
    }

    /**
     * @param  array{date_from?: string|null, date_to?: string|null, course_id?: int|null, student_id?: int|null}  $filters
     */
    public function exportCsv(User $user, string $type, array $filters): StreamedResponse
    {
        $this->assertCanAccess($user, $type);
        $columns = $this->columns($type);
        $filename = $type.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($user, $type, $filters, $columns) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fputcsv($out, array_column($columns, 'label'));

            foreach ($this->build($user, $type, $filters)->cursor() as $row) {
                $data = (array) $row;
                $line = [];
                foreach ($columns as $column) {
                    $line[] = $data[$column['key']] ?? '';
                }
                fputcsv($out, $line);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function assertCanAccess(User $user, string $type): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages([
                'report' => ['Unknown report type.'],
            ]);
        }

        $isAdmin = $user->hasPermission('reports.view');
        $isTeacher = $user->hasPermission('progress.view_assigned');

        if (in_array($type, self::ADMIN_ONLY, true)) {
            if (! $isAdmin) {
                throw new AuthorizationException('This report is limited to administrators.');
            }

            return;
        }

        if ($isAdmin) {
            return;
        }

        if (! $isTeacher || ! in_array($type, self::TEACHER_ALLOWED, true)) {
            throw new AuthorizationException('You cannot view this report.');
        }
    }

    /**
     * @param  array{date_from?: string|null, date_to?: string|null, course_id?: int|null, student_id?: int|null}  $filters
     */
    private function build(User $user, string $type, array $filters): Builder
    {
        $from = $this->date($filters['date_from'] ?? null)?->startOfDay();
        $to = $this->date($filters['date_to'] ?? null)?->endOfDay();
        $courseId = isset($filters['course_id']) ? (int) $filters['course_id'] : null;
        $studentId = isset($filters['student_id']) ? (int) $filters['student_id'] : null;
        $scopedCourseIds = $this->scopedCourseIds($user, $courseId);

        return match ($type) {
            self::TYPE_STUDENT_REGISTRATION => $this->studentRegistration($from, $to, $studentId),
            self::TYPE_COURSE_SALES => $this->courseSales($from, $to, $courseId, $studentId),
            self::TYPE_REVENUE => $this->revenue($from, $to, $studentId),
            self::TYPE_ENROLLMENT => $this->enrollment($from, $to, $courseId, $studentId, $scopedCourseIds),
            self::TYPE_PAYMENT_STATUS => $this->paymentStatus($from, $to, $studentId),
            self::TYPE_COURSE_COMPLETION => $this->courseCompletion($from, $to, $courseId, $studentId, $scopedCourseIds),
            self::TYPE_READING_PERFORMANCE => $this->mockPerformance(MockTest::MODULE_READING, $from, $to, $studentId, null),
            self::TYPE_LISTENING_PERFORMANCE => $this->mockPerformance(MockTest::MODULE_LISTENING, $from, $to, $studentId, null),
            self::TYPE_WRITING_EVALUATION => $this->writingEvaluation($from, $to, $studentId, null),
            self::TYPE_SPEAKING_EVALUATION => $this->speakingEvaluation($from, $to, $studentId, null),
            default => throw ValidationException::withMessages(['report' => ['Unknown report type.']]),
        };
    }

    /**
     * @return list<int>|null null means no course scoping (admin full access)
     */
    private function scopedCourseIds(User $user, ?int $courseId): ?array
    {
        if ($user->hasPermission('reports.view')) {
            return null;
        }

        $courseIds = DB::table('course_teachers')
            ->where('user_id', $user->id)
            ->when($courseId !== null, fn ($query) => $query->where('course_id', $courseId))
            ->pluck('course_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($courseId !== null && ! in_array($courseId, $courseIds, true)) {
            throw new AuthorizationException('You can only report on assigned courses.');
        }

        return $courseIds;
    }

    private function studentRegistration(?Carbon $from, ?Carbon $to, ?int $studentId): Builder
    {
        $studentRoleId = Role::query()->where('slug', 'student')->value('id');

        return User::query()
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.created_at as registered_at',
            ])
            ->whereExists(function ($query) use ($studentRoleId) {
                $query->select(DB::raw(1))
                    ->from('role_user')
                    ->whereColumn('role_user.user_id', 'users.id')
                    ->where('role_user.role_id', $studentRoleId);
            })
            ->when($studentId !== null, fn ($query) => $query->where('users.id', $studentId))
            ->when($from !== null, fn ($query) => $query->where('users.created_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('users.created_at', '<=', $to))
            ->orderByDesc('users.id')
            ->toBase();
    }

    private function courseSales(?Carbon $from, ?Carbon $to, ?int $courseId, ?int $studentId): Builder
    {
        return Order::query()
            ->from('orders')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('courses', 'courses.id', '=', 'order_items.course_id')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->where('orders.status', Order::PAID)
            ->when($courseId !== null, fn ($query) => $query->where('order_items.course_id', $courseId))
            ->when($studentId !== null, fn ($query) => $query->where('orders.user_id', $studentId))
            ->when($from !== null, fn ($query) => $query->where('orders.updated_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('orders.updated_at', '<=', $to))
            ->select([
                'order_items.id',
                'orders.order_number',
                'users.name as student_name',
                'users.email as student_email',
                'courses.title as course_title',
                DB::raw('COALESCE(order_items.discount_price, order_items.unit_price) as amount'),
                'orders.currency',
                'orders.updated_at as paid_at',
            ])
            ->orderByDesc('order_items.id')
            ->toBase();
    }

    private function revenue(?Carbon $from, ?Carbon $to, ?int $studentId): Builder
    {
        return Order::query()
            ->from('orders')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->where('orders.status', Order::PAID)
            ->when($studentId !== null, fn ($query) => $query->where('orders.user_id', $studentId))
            ->when($from !== null, fn ($query) => $query->where('orders.updated_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('orders.updated_at', '<=', $to))
            ->select([
                'orders.id',
                'orders.order_number',
                'users.name as student_name',
                'orders.total as amount',
                'orders.currency',
                'orders.updated_at as paid_at',
            ])
            ->orderByDesc('orders.id')
            ->toBase();
    }

    /**
     * @param  list<int>|null  $scopedCourseIds
     */
    private function enrollment(?Carbon $from, ?Carbon $to, ?int $courseId, ?int $studentId, ?array $scopedCourseIds): Builder
    {
        return Enrollment::query()
            ->from('enrollments')
            ->join('users', 'users.id', '=', 'enrollments.user_id')
            ->join('courses', 'courses.id', '=', 'enrollments.course_id')
            ->when($scopedCourseIds !== null, fn ($query) => $query->whereIn('enrollments.course_id', $scopedCourseIds ?: [0]))
            ->when($courseId !== null, fn ($query) => $query->where('enrollments.course_id', $courseId))
            ->when($studentId !== null, fn ($query) => $query->where('enrollments.user_id', $studentId))
            ->when($from !== null, fn ($query) => $query->where('enrollments.enrolled_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('enrollments.enrolled_at', '<=', $to))
            ->select([
                'enrollments.id',
                'users.name as student_name',
                'users.email as student_email',
                'courses.title as course_title',
                'enrollments.status',
                'enrollments.enrolled_at',
            ])
            ->orderByDesc('enrollments.id')
            ->toBase();
    }

    private function paymentStatus(?Carbon $from, ?Carbon $to, ?int $studentId): Builder
    {
        return Payment::query()
            ->from('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->when($studentId !== null, fn ($query) => $query->where('orders.user_id', $studentId))
            ->when($from !== null, fn ($query) => $query->where('payments.created_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('payments.created_at', '<=', $to))
            ->select([
                'payments.id',
                'orders.order_number',
                'users.name as student_name',
                'payments.gateway',
                'payments.status',
                'payments.amount',
                'payments.currency',
                'payments.created_at',
            ])
            ->orderByDesc('payments.id')
            ->toBase();
    }

    /**
     * @param  list<int>|null  $scopedCourseIds
     */
    private function courseCompletion(?Carbon $from, ?Carbon $to, ?int $courseId, ?int $studentId, ?array $scopedCourseIds): Builder
    {
        return Certificate::query()
            ->from('certificates')
            ->join('users', 'users.id', '=', 'certificates.user_id')
            ->join('courses', 'courses.id', '=', 'certificates.course_id')
            ->when($scopedCourseIds !== null, fn ($query) => $query->whereIn('certificates.course_id', $scopedCourseIds ?: [0]))
            ->when($courseId !== null, fn ($query) => $query->where('certificates.course_id', $courseId))
            ->when($studentId !== null, fn ($query) => $query->where('certificates.user_id', $studentId))
            ->when($from !== null, fn ($query) => $query->where('certificates.issued_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('certificates.issued_at', '<=', $to))
            ->select([
                'certificates.id',
                'certificates.certificate_number',
                'users.name as student_name',
                'courses.title as course_title',
                'certificates.issued_at',
            ])
            ->orderByDesc('certificates.id')
            ->toBase();
    }

    /**
     * @param  list<int>|null  $scopedStudentIds
     */
    private function mockPerformance(string $module, ?Carbon $from, ?Carbon $to, ?int $studentId, ?array $scopedStudentIds): Builder
    {
        return TestAttempt::query()
            ->from('test_attempts')
            ->join('users', 'users.id', '=', 'test_attempts.user_id')
            ->join('mock_tests', 'mock_tests.id', '=', 'test_attempts.mock_test_id')
            ->leftJoin('test_results', 'test_results.test_attempt_id', '=', 'test_attempts.id')
            ->where('mock_tests.module', $module)
            ->where('test_attempts.status', TestAttempt::STATUS_SUBMITTED)
            ->when($scopedStudentIds !== null, fn ($query) => $query->whereIn('test_attempts.user_id', $scopedStudentIds ?: [0]))
            ->when($studentId !== null, fn ($query) => $query->where('test_attempts.user_id', $studentId))
            ->when($from !== null, fn ($query) => $query->where('test_attempts.submitted_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('test_attempts.submitted_at', '<=', $to))
            ->select([
                'test_attempts.id',
                'users.name as student_name',
                'mock_tests.title as test_title',
                'mock_tests.exam_type',
                'test_results.raw_score',
                'test_results.max_score',
                'test_results.band_score',
                'test_attempts.submitted_at',
            ])
            ->orderByDesc('test_attempts.id')
            ->toBase();
    }

    /**
     * @param  list<int>|null  $scopedStudentIds
     */
    private function writingEvaluation(?Carbon $from, ?Carbon $to, ?int $studentId, ?array $scopedStudentIds): Builder
    {
        return WritingEvaluation::query()
            ->from('writing_evaluations')
            ->join('writing_submissions', 'writing_submissions.id', '=', 'writing_evaluations.writing_submission_id')
            ->join('users', 'users.id', '=', 'writing_submissions.user_id')
            ->join('writing_tasks', 'writing_tasks.id', '=', 'writing_submissions.writing_task_id')
            ->when($scopedStudentIds !== null, fn ($query) => $query->whereIn('writing_submissions.user_id', $scopedStudentIds ?: [0]))
            ->when($studentId !== null, fn ($query) => $query->where('writing_submissions.user_id', $studentId))
            ->when($from !== null, fn ($query) => $query->where('writing_evaluations.evaluated_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('writing_evaluations.evaluated_at', '<=', $to))
            ->select([
                'writing_evaluations.id',
                'users.name as student_name',
                'writing_tasks.title as task_title',
                'writing_evaluations.overall_band',
                'writing_evaluations.task_response',
                'writing_evaluations.coherence',
                'writing_evaluations.lexical',
                'writing_evaluations.grammar',
                'writing_evaluations.evaluated_at',
            ])
            ->orderByDesc('writing_evaluations.id')
            ->toBase();
    }

    /**
     * @param  list<int>|null  $scopedStudentIds
     */
    private function speakingEvaluation(?Carbon $from, ?Carbon $to, ?int $studentId, ?array $scopedStudentIds): Builder
    {
        return SpeakingEvaluation::query()
            ->from('speaking_evaluations')
            ->join('speaking_recordings', 'speaking_recordings.id', '=', 'speaking_evaluations.speaking_recording_id')
            ->join('users', 'users.id', '=', 'speaking_recordings.user_id')
            ->join('speaking_tasks', 'speaking_tasks.id', '=', 'speaking_recordings.speaking_task_id')
            ->when($scopedStudentIds !== null, fn ($query) => $query->whereIn('speaking_recordings.user_id', $scopedStudentIds ?: [0]))
            ->when($studentId !== null, fn ($query) => $query->where('speaking_recordings.user_id', $studentId))
            ->when($from !== null, fn ($query) => $query->where('speaking_evaluations.evaluated_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('speaking_evaluations.evaluated_at', '<=', $to))
            ->select([
                'speaking_evaluations.id',
                'users.name as student_name',
                'speaking_tasks.title as task_title',
                'speaking_evaluations.overall_band',
                'speaking_evaluations.fluency',
                'speaking_evaluations.lexical',
                'speaking_evaluations.grammar',
                'speaking_evaluations.pronunciation',
                'speaking_evaluations.evaluated_at',
            ])
            ->orderByDesc('speaking_evaluations.id')
            ->toBase();
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function columns(string $type): array
    {
        return match ($type) {
            self::TYPE_STUDENT_REGISTRATION => [
                ['key' => 'id', 'label' => 'ID'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'email', 'label' => 'Email'],
                ['key' => 'registered_at', 'label' => 'Registered at'],
            ],
            self::TYPE_COURSE_SALES => [
                ['key' => 'order_number', 'label' => 'Order'],
                ['key' => 'student_name', 'label' => 'Student'],
                ['key' => 'student_email', 'label' => 'Email'],
                ['key' => 'course_title', 'label' => 'Course'],
                ['key' => 'amount', 'label' => 'Amount'],
                ['key' => 'currency', 'label' => 'Currency'],
                ['key' => 'paid_at', 'label' => 'Paid at'],
            ],
            self::TYPE_REVENUE => [
                ['key' => 'order_number', 'label' => 'Order'],
                ['key' => 'student_name', 'label' => 'Student'],
                ['key' => 'amount', 'label' => 'Amount'],
                ['key' => 'currency', 'label' => 'Currency'],
                ['key' => 'paid_at', 'label' => 'Paid at'],
            ],
            self::TYPE_ENROLLMENT => [
                ['key' => 'student_name', 'label' => 'Student'],
                ['key' => 'student_email', 'label' => 'Email'],
                ['key' => 'course_title', 'label' => 'Course'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'enrolled_at', 'label' => 'Enrolled at'],
            ],
            self::TYPE_PAYMENT_STATUS => [
                ['key' => 'order_number', 'label' => 'Order'],
                ['key' => 'student_name', 'label' => 'Student'],
                ['key' => 'gateway', 'label' => 'Gateway'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'amount', 'label' => 'Amount'],
                ['key' => 'currency', 'label' => 'Currency'],
                ['key' => 'created_at', 'label' => 'Created at'],
            ],
            self::TYPE_COURSE_COMPLETION => [
                ['key' => 'certificate_number', 'label' => 'Certificate'],
                ['key' => 'student_name', 'label' => 'Student'],
                ['key' => 'course_title', 'label' => 'Course'],
                ['key' => 'issued_at', 'label' => 'Issued at'],
            ],
            self::TYPE_READING_PERFORMANCE, self::TYPE_LISTENING_PERFORMANCE => [
                ['key' => 'student_name', 'label' => 'Student'],
                ['key' => 'test_title', 'label' => 'Test'],
                ['key' => 'exam_type', 'label' => 'Exam type'],
                ['key' => 'raw_score', 'label' => 'Raw'],
                ['key' => 'max_score', 'label' => 'Max'],
                ['key' => 'band_score', 'label' => 'Band'],
                ['key' => 'submitted_at', 'label' => 'Submitted at'],
            ],
            self::TYPE_WRITING_EVALUATION => [
                ['key' => 'student_name', 'label' => 'Student'],
                ['key' => 'task_title', 'label' => 'Task'],
                ['key' => 'overall_band', 'label' => 'Overall'],
                ['key' => 'task_response', 'label' => 'TR'],
                ['key' => 'coherence', 'label' => 'CC'],
                ['key' => 'lexical', 'label' => 'LR'],
                ['key' => 'grammar', 'label' => 'GRA'],
                ['key' => 'evaluated_at', 'label' => 'Evaluated at'],
            ],
            self::TYPE_SPEAKING_EVALUATION => [
                ['key' => 'student_name', 'label' => 'Student'],
                ['key' => 'task_title', 'label' => 'Task'],
                ['key' => 'overall_band', 'label' => 'Overall'],
                ['key' => 'fluency', 'label' => 'FC'],
                ['key' => 'lexical', 'label' => 'LR'],
                ['key' => 'grammar', 'label' => 'GRA'],
                ['key' => 'pronunciation', 'label' => 'P'],
                ['key' => 'evaluated_at', 'label' => 'Evaluated at'],
            ],
            default => [],
        };
    }

    private function date(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
