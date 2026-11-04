<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CourseCategoryController;
use App\Http\Controllers\Api\V1\CourseController;
use App\Http\Controllers\Api\V1\CourseSectionController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\StudentDashboardController;
use App\Http\Controllers\Api\V1\LessonController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\LandingController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PublicCourseController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\UserRoleController;
use App\Http\Controllers\Api\V1\LessonPlaybackController;
use App\Http\Controllers\Api\V1\LessonEngagementController;
use App\Http\Controllers\Api\V1\LessonProgressController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\AdminOrderController;
use App\Http\Controllers\Api\V1\AdminPaymentController;
use App\Http\Controllers\Api\V1\AdminStudentController;
use App\Http\Controllers\Api\V1\AdminAccountController;
use App\Http\Controllers\Api\V1\AdminFinanceController;
use App\Http\Controllers\Api\V1\BkashPaymentController;
use App\Http\Controllers\Api\V1\EnrollmentController;
use App\Http\Controllers\Api\V1\SslCommerzPaymentController;
use App\Http\Controllers\Api\V1\BandConversionController;
use App\Http\Controllers\Api\V1\ListeningAttemptController;
use App\Http\Controllers\Api\V1\ListeningMockTestController;
use App\Http\Controllers\Api\V1\ReadingAttemptController;
use App\Http\Controllers\Api\V1\ReadingMockTestController;
use App\Http\Controllers\Api\V1\VideoController;
use App\Http\Controllers\Api\V1\VideoWebhookController;
use App\Http\Controllers\Api\V1\ActivityLogController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\SpeakingEvaluationController;
use App\Http\Controllers\Api\V1\SpeakingRecordingController;
use App\Http\Controllers\Api\V1\SpeakingTaskController;
use App\Http\Controllers\Api\V1\WritingEvaluationController;
use App\Http\Controllers\Api\V1\WritingSubmissionController;
use App\Http\Controllers\Api\V1\WritingTaskController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/landing', LandingController::class);
Route::get('/settings/public', [SettingController::class, 'publicBranding']);
Route::get('/categories', [PublicCourseController::class, 'categories']);
Route::get('/courses', [PublicCourseController::class, 'index']);
Route::get('/courses/{slug}', [PublicCourseController::class, 'show'])->where('slug', '[A-Za-z0-9_-]+');
Route::post('/webhooks/cloudflare/stream', VideoWebhookController::class);

Route::post('/payments/sslcommerz/ipn', [SslCommerzPaymentController::class, 'ipn']);
Route::match(['get', 'post'], '/payments/sslcommerz/success', [SslCommerzPaymentController::class, 'success']);
Route::match(['get', 'post'], '/payments/sslcommerz/fail', [SslCommerzPaymentController::class, 'fail']);
Route::match(['get', 'post'], '/payments/sslcommerz/cancel', [SslCommerzPaymentController::class, 'cancel']);
Route::match(['get', 'post'], '/payments/bkash/callback', [BkashPaymentController::class, 'callback']);

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:reset-password');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [ProfileController::class, 'me']);
    Route::get('/user', [ProfileController::class, 'me']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/password', [ProfileController::class, 'changePassword']);

    Route::get('/lessons/{lesson}/playback', LessonPlaybackController::class)->whereNumber('lesson');
    Route::put('/lessons/{lesson}/progress', [LessonProgressController::class, 'update'])->whereNumber('lesson');
    Route::post('/lessons/{lesson}/progress/complete', [LessonProgressController::class, 'complete'])->whereNumber('lesson');
    Route::post('/lessons/{lesson}/reaction', [LessonEngagementController::class, 'react'])->whereNumber('lesson');
    Route::post('/lessons/{lesson}/feedback', [LessonEngagementController::class, 'feedback'])->whereNumber('lesson');

    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show'])->whereNumber('order');
    Route::post('/orders/{order}/payments/sslcommerz', [SslCommerzPaymentController::class, 'initiate'])->whereNumber('order');
    Route::post('/orders/{order}/payments/bkash', [BkashPaymentController::class, 'initiate'])->whereNumber('order');
    Route::get('/payments/{payment}', [SslCommerzPaymentController::class, 'show'])->whereNumber('payment');

    Route::get('/student/dashboard', StudentDashboardController::class);
    Route::get('/student/enrollments', [EnrollmentController::class, 'index']);
    Route::get('/student/courses/{slug}', [EnrollmentController::class, 'learning'])->where('slug', '[A-Za-z0-9_-]+');

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

    Route::get('/reading-tests', [ReadingAttemptController::class, 'index']);
    Route::get('/reading-tests/{slug}', [ReadingAttemptController::class, 'show'])->where('slug', '[A-Za-z0-9_-]+');
    Route::post('/reading-tests/{slug}/attempts', [ReadingAttemptController::class, 'start'])->where('slug', '[A-Za-z0-9_-]+');
    Route::get('/reading-attempts/{attempt}', [ReadingAttemptController::class, 'showAttempt'])->whereNumber('attempt');
    Route::put('/reading-attempts/{attempt}/answers', [ReadingAttemptController::class, 'saveAnswers'])->whereNumber('attempt');
    Route::post('/reading-attempts/{attempt}/submit', [ReadingAttemptController::class, 'submit'])->whereNumber('attempt');
    Route::get('/student/reading-attempts', [ReadingAttemptController::class, 'history']);

    Route::get('/listening-tests', [ListeningAttemptController::class, 'index']);
    Route::get('/listening-tests/{slug}', [ListeningAttemptController::class, 'show'])->where('slug', '[A-Za-z0-9_-]+');
    Route::post('/listening-tests/{slug}/attempts', [ListeningAttemptController::class, 'start'])->where('slug', '[A-Za-z0-9_-]+');
    Route::get('/listening-attempts/{attempt}', [ListeningAttemptController::class, 'showAttempt'])->whereNumber('attempt');
    Route::put('/listening-attempts/{attempt}/answers', [ListeningAttemptController::class, 'saveAnswers'])->whereNumber('attempt');
    Route::post('/listening-attempts/{attempt}/submit', [ListeningAttemptController::class, 'submit'])->whereNumber('attempt');
    Route::get('/listening-attempts/{attempt}/sections/{section}/audio', [ListeningAttemptController::class, 'streamAudio'])->whereNumber(['attempt', 'section']);
    Route::get('/student/listening-attempts', [ListeningAttemptController::class, 'history']);

    Route::get('/writing-tasks', [WritingTaskController::class, 'studentIndex']);
    Route::get('/writing-tasks/{writingTask}', [WritingTaskController::class, 'studentShow'])->whereNumber('writingTask');
    Route::get('/writing-tasks/{writingTask}/submission', [WritingSubmissionController::class, 'showForTask'])->whereNumber('writingTask');
    Route::put('/writing-tasks/{writingTask}/submission', [WritingSubmissionController::class, 'saveDraft'])->whereNumber('writingTask');
    Route::post('/writing-tasks/{writingTask}/submission/submit', [WritingSubmissionController::class, 'submit'])->whereNumber('writingTask');
    Route::get('/student/writing-submissions', [WritingSubmissionController::class, 'index']);
    Route::get('/student/writing-submissions/{submission}', [WritingSubmissionController::class, 'show'])->whereNumber('submission');
    Route::get('/student/writing-submissions/{submission}/evaluation', [WritingEvaluationController::class, 'studentShow'])->whereNumber('submission');

    Route::get('/speaking-tasks', [SpeakingTaskController::class, 'studentIndex']);
    Route::get('/speaking-tasks/{speakingTask}', [SpeakingTaskController::class, 'studentShow'])->whereNumber('speakingTask');
    Route::get('/speaking-tasks/{speakingTask}/recording', [SpeakingRecordingController::class, 'showForTask'])->whereNumber('speakingTask');
    Route::post('/speaking-tasks/{speakingTask}/recording', [SpeakingRecordingController::class, 'upload'])->whereNumber('speakingTask');
    Route::post('/speaking-tasks/{speakingTask}/recording/submit', [SpeakingRecordingController::class, 'submit'])->whereNumber('speakingTask');
    Route::get('/speaking-recordings/{recording}/audio', [SpeakingRecordingController::class, 'stream'])->whereNumber('recording');
    Route::get('/student/speaking-recordings', [SpeakingRecordingController::class, 'index']);
    Route::get('/student/speaking-recordings/{recording}', [SpeakingRecordingController::class, 'show'])->whereNumber('recording');

    Route::middleware('permission:writing.evaluate')->group(function () {
        Route::get('/teacher/writing-submissions', [WritingEvaluationController::class, 'index']);
        Route::get('/teacher/writing-submissions/{submission}', [WritingEvaluationController::class, 'show'])->whereNumber('submission');
        Route::post('/teacher/writing-submissions/{submission}/evaluate', [WritingEvaluationController::class, 'store'])->whereNumber('submission');
        Route::put('/teacher/writing-evaluations/{evaluation}', [WritingEvaluationController::class, 'update'])->whereNumber('evaluation');
    });

    Route::middleware('permission:speaking.evaluate')->group(function () {
        Route::get('/teacher/speaking-recordings', [SpeakingEvaluationController::class, 'index']);
        Route::get('/teacher/speaking-recordings/{recording}', [SpeakingEvaluationController::class, 'show'])->whereNumber('recording');
        Route::post('/teacher/speaking-recordings/{recording}/evaluate', [SpeakingEvaluationController::class, 'store'])->whereNumber('recording');
        Route::put('/teacher/speaking-evaluations/{evaluation}', [SpeakingEvaluationController::class, 'update'])->whereNumber('evaluation');
    });

    Route::middleware('permission:roles.manage,users.manage')->group(function () {
        Route::get('/admin/roles', [RoleController::class, 'index']);
    });

    Route::middleware('permission:roles.manage')->group(function () {
        Route::post('/admin/roles', [RoleController::class, 'store']);
        Route::put('/admin/roles/{role}', [RoleController::class, 'update']);
        Route::delete('/admin/roles/{role}', [RoleController::class, 'destroy']);
        Route::put('/admin/roles/{role}/permissions', [RoleController::class, 'syncPermissions']);

        Route::get('/admin/permissions', [PermissionController::class, 'index']);
        Route::post('/admin/permissions', [PermissionController::class, 'store']);
        Route::delete('/admin/permissions/{permission}', [PermissionController::class, 'destroy']);
    });

    Route::middleware('permission:categories.manage')->group(function () {
        Route::get('/admin/categories', [CourseCategoryController::class, 'index']);
        Route::post('/admin/categories', [CourseCategoryController::class, 'store']);
        Route::get('/admin/categories/{category}', [CourseCategoryController::class, 'show']);
        Route::put('/admin/categories/{category}', [CourseCategoryController::class, 'update']);
        Route::delete('/admin/categories/{category}', [CourseCategoryController::class, 'destroy']);
    });

    Route::middleware('permission:courses.manage')->group(function () {
        Route::get('/admin/courses/form-options', [CourseController::class, 'formOptions']);
        Route::get('/admin/courses', [CourseController::class, 'index']);
        Route::post('/admin/courses', [CourseController::class, 'store']);
        Route::get('/admin/courses/{course}', [CourseController::class, 'show'])->whereNumber('course');
        Route::put('/admin/courses/{course}', [CourseController::class, 'update'])->whereNumber('course');
        Route::delete('/admin/courses/{course}', [CourseController::class, 'destroy'])->whereNumber('course');
        Route::put('/admin/courses/{course}/publication', [CourseController::class, 'publication'])->whereNumber('course');
        Route::put('/admin/courses/{course}/teachers', [CourseController::class, 'syncTeachers'])->whereNumber('course');
        Route::post('/admin/courses/{course}/thumbnail', [CourseController::class, 'thumbnail'])->whereNumber('course');
        Route::delete('/admin/courses/{course}/thumbnail', [CourseController::class, 'destroyThumbnail'])->whereNumber('course');
    });

    Route::middleware('permission:lessons.manage,lessons.manage_assigned')->group(function () {
        Route::get('/admin/courses/{course}/sections', [CourseSectionController::class, 'index'])->whereNumber('course');
        Route::post('/admin/courses/{course}/sections', [CourseSectionController::class, 'store'])->whereNumber('course');
        Route::put('/admin/courses/{course}/sections/order', [CourseSectionController::class, 'reorder'])->whereNumber('course');
        Route::put('/admin/courses/{course}/sections/{section}', [CourseSectionController::class, 'update'])->whereNumber(['course', 'section']);
        Route::delete('/admin/courses/{course}/sections/{section}', [CourseSectionController::class, 'destroy'])->whereNumber(['course', 'section']);

        Route::post('/admin/courses/{course}/sections/{section}/lessons', [LessonController::class, 'store'])->whereNumber(['course', 'section']);
        Route::put('/admin/courses/{course}/sections/{section}/lessons/order', [LessonController::class, 'reorder'])->whereNumber(['course', 'section']);
        Route::put('/admin/courses/{course}/sections/{section}/lessons/{lesson}', [LessonController::class, 'update'])->whereNumber(['course', 'section', 'lesson']);
        Route::delete('/admin/courses/{course}/sections/{section}/lessons/{lesson}', [LessonController::class, 'destroy'])->whereNumber(['course', 'section', 'lesson']);
    });

    Route::middleware('permission:videos.manage')->group(function () {
        Route::get('/admin/lessons/{lesson}/video', [VideoController::class, 'show'])->whereNumber('lesson');
        Route::post('/admin/lessons/{lesson}/video', [VideoController::class, 'store'])->whereNumber('lesson');
        Route::post('/admin/lessons/{lesson}/video/cover', [VideoController::class, 'storeCover'])->whereNumber('lesson');
        Route::delete('/admin/lessons/{lesson}/video/cover', [VideoController::class, 'destroyCover'])->whereNumber('lesson');
    });

    Route::middleware('permission:payments.manage')->group(function () {
        Route::get('/admin/orders', [AdminOrderController::class, 'index']);
        Route::get('/admin/orders/{order}', [AdminOrderController::class, 'show'])->whereNumber('order');
        Route::post('/admin/orders/{order}/cancel', [AdminOrderController::class, 'cancel'])->whereNumber('order');
        Route::get('/admin/payments', [AdminPaymentController::class, 'index']);
        Route::get('/admin/payments/pending-orders', [AdminPaymentController::class, 'pendingOrders']);
        Route::post('/admin/orders/{order}/confirm-bkash', [AdminPaymentController::class, 'confirmBkash'])->whereNumber('order');
        Route::post('/admin/payments/{payment}/refund', [EnrollmentController::class, 'refund'])->whereNumber('payment');

        Route::get('/admin/finance/summary', [AdminFinanceController::class, 'summary']);
        Route::get('/admin/finance/income', [AdminFinanceController::class, 'income']);
        Route::get('/admin/finance/expenses', [AdminFinanceController::class, 'expenses']);
        Route::post('/admin/finance/expenses', [AdminFinanceController::class, 'storeExpense']);
        Route::put('/admin/finance/expenses/{expense}', [AdminFinanceController::class, 'updateExpense'])->whereNumber('expense');
        Route::delete('/admin/finance/expenses/{expense}', [AdminFinanceController::class, 'destroyExpense'])->whereNumber('expense');
    });

    Route::middleware('permission:mock_tests.manage')->group(function () {
        Route::get('/admin/reading-tests', [ReadingMockTestController::class, 'index']);
        Route::post('/admin/reading-tests', [ReadingMockTestController::class, 'store']);
        Route::get('/admin/reading-tests/{readingTest}', [ReadingMockTestController::class, 'show'])->whereNumber('readingTest');
        Route::put('/admin/reading-tests/{readingTest}', [ReadingMockTestController::class, 'update'])->whereNumber('readingTest');
        Route::put('/admin/reading-tests/{readingTest}/publication', [ReadingMockTestController::class, 'publication'])->whereNumber('readingTest');
        Route::delete('/admin/reading-tests/{readingTest}', [ReadingMockTestController::class, 'destroy'])->whereNumber('readingTest');

        Route::post('/admin/reading-tests/{readingTest}/sections', [ReadingMockTestController::class, 'storeSection'])->whereNumber('readingTest');
        Route::put('/admin/reading-tests/{readingTest}/sections/order', [ReadingMockTestController::class, 'reorderSections'])->whereNumber('readingTest');
        Route::put('/admin/reading-tests/{readingTest}/sections/{section}', [ReadingMockTestController::class, 'updateSection'])->whereNumber(['readingTest', 'section']);
        Route::delete('/admin/reading-tests/{readingTest}/sections/{section}', [ReadingMockTestController::class, 'destroySection'])->whereNumber(['readingTest', 'section']);

        Route::post('/admin/reading-tests/{readingTest}/sections/{section}/passages', [ReadingMockTestController::class, 'storePassage'])->whereNumber(['readingTest', 'section']);
        Route::put('/admin/reading-tests/{readingTest}/sections/{section}/passages/{passage}', [ReadingMockTestController::class, 'updatePassage'])->whereNumber(['readingTest', 'section', 'passage']);
        Route::delete('/admin/reading-tests/{readingTest}/sections/{section}/passages/{passage}', [ReadingMockTestController::class, 'destroyPassage'])->whereNumber(['readingTest', 'section', 'passage']);

        Route::post('/admin/reading-tests/{readingTest}/sections/{section}/groups', [ReadingMockTestController::class, 'storeGroup'])->whereNumber(['readingTest', 'section']);
        Route::put('/admin/reading-tests/{readingTest}/sections/{section}/groups/{group}', [ReadingMockTestController::class, 'updateGroup'])->whereNumber(['readingTest', 'section', 'group']);
        Route::delete('/admin/reading-tests/{readingTest}/sections/{section}/groups/{group}', [ReadingMockTestController::class, 'destroyGroup'])->whereNumber(['readingTest', 'section', 'group']);

        Route::post('/admin/reading-tests/{readingTest}/sections/{section}/groups/{group}/options', [ReadingMockTestController::class, 'storeOption'])->whereNumber(['readingTest', 'section', 'group']);
        Route::put('/admin/reading-tests/{readingTest}/sections/{section}/groups/{group}/options/{option}', [ReadingMockTestController::class, 'updateOption'])->whereNumber(['readingTest', 'section', 'group', 'option']);
        Route::delete('/admin/reading-tests/{readingTest}/sections/{section}/groups/{group}/options/{option}', [ReadingMockTestController::class, 'destroyOption'])->whereNumber(['readingTest', 'section', 'group', 'option']);

        Route::post('/admin/reading-tests/{readingTest}/sections/{section}/groups/{group}/questions', [ReadingMockTestController::class, 'storeQuestion'])->whereNumber(['readingTest', 'section', 'group']);
        Route::put('/admin/reading-tests/{readingTest}/sections/{section}/groups/{group}/questions/{question}', [ReadingMockTestController::class, 'updateQuestion'])->whereNumber(['readingTest', 'section', 'group', 'question']);
        Route::delete('/admin/reading-tests/{readingTest}/sections/{section}/groups/{group}/questions/{question}', [ReadingMockTestController::class, 'destroyQuestion'])->whereNumber(['readingTest', 'section', 'group', 'question']);

        Route::get('/admin/band-conversions', [BandConversionController::class, 'index']);
        Route::put('/admin/band-conversions', [BandConversionController::class, 'sync']);

        Route::get('/admin/listening-tests', [ListeningMockTestController::class, 'index']);
        Route::post('/admin/listening-tests', [ListeningMockTestController::class, 'store']);
        Route::get('/admin/listening-tests/{listeningTest}', [ListeningMockTestController::class, 'show'])->whereNumber('listeningTest');
        Route::put('/admin/listening-tests/{listeningTest}', [ListeningMockTestController::class, 'update'])->whereNumber('listeningTest');
        Route::put('/admin/listening-tests/{listeningTest}/publication', [ListeningMockTestController::class, 'publication'])->whereNumber('listeningTest');
        Route::delete('/admin/listening-tests/{listeningTest}', [ListeningMockTestController::class, 'destroy'])->whereNumber('listeningTest');

        Route::put('/admin/listening-tests/{listeningTest}/sections/{section}', [ListeningMockTestController::class, 'updateSection'])->whereNumber(['listeningTest', 'section']);
        Route::post('/admin/listening-tests/{listeningTest}/sections/{section}/audio', [ListeningMockTestController::class, 'storeAudio'])->whereNumber(['listeningTest', 'section']);
        Route::delete('/admin/listening-tests/{listeningTest}/sections/{section}/audio', [ListeningMockTestController::class, 'destroyAudio'])->whereNumber(['listeningTest', 'section']);
        Route::get('/admin/listening-tests/{listeningTest}/sections/{section}/audio', [ListeningMockTestController::class, 'streamAudio'])->whereNumber(['listeningTest', 'section']);

        Route::post('/admin/listening-tests/{listeningTest}/sections/{section}/groups', [ListeningMockTestController::class, 'storeGroup'])->whereNumber(['listeningTest', 'section']);
        Route::put('/admin/listening-tests/{listeningTest}/sections/{section}/groups/{group}', [ListeningMockTestController::class, 'updateGroup'])->whereNumber(['listeningTest', 'section', 'group']);
        Route::delete('/admin/listening-tests/{listeningTest}/sections/{section}/groups/{group}', [ListeningMockTestController::class, 'destroyGroup'])->whereNumber(['listeningTest', 'section', 'group']);

        Route::post('/admin/listening-tests/{listeningTest}/sections/{section}/groups/{group}/options', [ListeningMockTestController::class, 'storeOption'])->whereNumber(['listeningTest', 'section', 'group']);
        Route::put('/admin/listening-tests/{listeningTest}/sections/{section}/groups/{group}/options/{option}', [ListeningMockTestController::class, 'updateOption'])->whereNumber(['listeningTest', 'section', 'group', 'option']);
        Route::delete('/admin/listening-tests/{listeningTest}/sections/{section}/groups/{group}/options/{option}', [ListeningMockTestController::class, 'destroyOption'])->whereNumber(['listeningTest', 'section', 'group', 'option']);

        Route::post('/admin/listening-tests/{listeningTest}/sections/{section}/groups/{group}/questions', [ListeningMockTestController::class, 'storeQuestion'])->whereNumber(['listeningTest', 'section', 'group']);
        Route::put('/admin/listening-tests/{listeningTest}/sections/{section}/groups/{group}/questions/{question}', [ListeningMockTestController::class, 'updateQuestion'])->whereNumber(['listeningTest', 'section', 'group', 'question']);
        Route::delete('/admin/listening-tests/{listeningTest}/sections/{section}/groups/{group}/questions/{question}', [ListeningMockTestController::class, 'destroyQuestion'])->whereNumber(['listeningTest', 'section', 'group', 'question']);
    });

    Route::middleware('permission:writing.manage')->group(function () {
        Route::get('/admin/writing-tasks', [WritingTaskController::class, 'index']);
        Route::post('/admin/writing-tasks', [WritingTaskController::class, 'store']);
        Route::get('/admin/writing-tasks/{writingTask}', [WritingTaskController::class, 'show'])->whereNumber('writingTask');
        Route::put('/admin/writing-tasks/{writingTask}', [WritingTaskController::class, 'update'])->whereNumber('writingTask');
        Route::put('/admin/writing-tasks/{writingTask}/publication', [WritingTaskController::class, 'publication'])->whereNumber('writingTask');
        Route::delete('/admin/writing-tasks/{writingTask}', [WritingTaskController::class, 'destroy'])->whereNumber('writingTask');
        Route::post('/admin/writing-tasks/{writingTask}/image', [WritingTaskController::class, 'storeImage'])->whereNumber('writingTask');
        Route::delete('/admin/writing-tasks/{writingTask}/image', [WritingTaskController::class, 'destroyImage'])->whereNumber('writingTask');
    });

    Route::middleware('permission:speaking.manage')->group(function () {
        Route::get('/admin/speaking-tasks', [SpeakingTaskController::class, 'index']);
        Route::post('/admin/speaking-tasks', [SpeakingTaskController::class, 'store']);
        Route::get('/admin/speaking-tasks/{speakingTask}', [SpeakingTaskController::class, 'show'])->whereNumber('speakingTask');
        Route::put('/admin/speaking-tasks/{speakingTask}', [SpeakingTaskController::class, 'update'])->whereNumber('speakingTask');
        Route::put('/admin/speaking-tasks/{speakingTask}/publication', [SpeakingTaskController::class, 'publication'])->whereNumber('speakingTask');
        Route::delete('/admin/speaking-tasks/{speakingTask}', [SpeakingTaskController::class, 'destroy'])->whereNumber('speakingTask');
        Route::post('/admin/speaking-tasks/{speakingTask}/questions', [SpeakingTaskController::class, 'storeQuestion'])->whereNumber('speakingTask');
        Route::put('/admin/speaking-tasks/{speakingTask}/questions/{question}', [SpeakingTaskController::class, 'updateQuestion'])->whereNumber(['speakingTask', 'question']);
        Route::delete('/admin/speaking-tasks/{speakingTask}/questions/{question}', [SpeakingTaskController::class, 'destroyQuestion'])->whereNumber(['speakingTask', 'question']);
    });

    Route::middleware('permission:reports.view')->group(function () {
        Route::get('/admin/dashboard', DashboardController::class);
    });

    Route::middleware('permission:reports.view,progress.view_assigned')->group(function () {
        Route::get('/reports', [ReportController::class, 'index']);
        Route::get('/reports/{report}', [ReportController::class, 'show'])->where('report', '[a-z_]+');
        Route::get('/reports/{report}/export', [ReportController::class, 'export'])->where('report', '[a-z_]+');
    });

    Route::middleware('permission:settings.manage')->group(function () {
        Route::get('/admin/settings', [SettingController::class, 'show']);
        Route::put('/admin/settings', [SettingController::class, 'update']);
        Route::post('/admin/settings/logo', [SettingController::class, 'storeLogo']);
        Route::delete('/admin/settings/logo', [SettingController::class, 'destroyLogo']);
        Route::post('/admin/settings/bkash-qr', [SettingController::class, 'storeBkashQr']);
        Route::delete('/admin/settings/bkash-qr', [SettingController::class, 'destroyBkashQr']);
        Route::get('/admin/activity-logs', [ActivityLogController::class, 'index']);
    });

    Route::middleware('permission:users.manage')->group(function () {
        Route::get('/admin/users', [UserRoleController::class, 'index']);
        Route::put('/admin/users/{user}/roles', [UserRoleController::class, 'sync']);
        Route::get('/admin/accounts', [AdminAccountController::class, 'index']);
        Route::post('/admin/accounts', [AdminAccountController::class, 'store']);
        Route::get('/admin/accounts/{account}', [AdminAccountController::class, 'show'])->whereNumber('account');
        Route::put('/admin/accounts/{account}', [AdminAccountController::class, 'update'])->whereNumber('account');
        Route::post('/admin/accounts/{account}/active', [AdminAccountController::class, 'setActive'])->whereNumber('account');
        Route::post('/admin/accounts/{account}/reset-password', [AdminAccountController::class, 'resetPassword'])->whereNumber('account');
        Route::get('/admin/students', [AdminStudentController::class, 'index']);
        Route::get('/admin/students/{student}', [AdminStudentController::class, 'show'])->whereNumber('student');
    });
});
