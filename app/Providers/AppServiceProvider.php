<?php

namespace App\Providers;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Expense;
use App\Models\MockTest;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Video;
use App\Models\SpeakingTask;
use App\Models\WritingTask;
use App\Policies\CourseCategoryPolicy;
use App\Policies\CoursePolicy;
use App\Policies\DashboardPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\MockTestPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PermissionPolicy;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Policies\VideoPolicy;
use App\Policies\SpeakingTaskPolicy;
use App\Policies\WritingTaskPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Course::class, CoursePolicy::class);
        Gate::policy(CourseCategory::class, CourseCategoryPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Video::class, VideoPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(Expense::class, ExpensePolicy::class);
        Gate::policy(MockTest::class, MockTestPolicy::class);
        Gate::policy(WritingTask::class, WritingTaskPolicy::class);
        Gate::policy(SpeakingTask::class, SpeakingTaskPolicy::class);
        Gate::define('viewDashboard', [DashboardPolicy::class, 'view']);

        Route::bind('readingTest', function (string $value) {
            return MockTest::query()
                ->where('module', MockTest::MODULE_READING)
                ->whereKey($value)
                ->firstOrFail();
        });

        Route::bind('listeningTest', function (string $value) {
            return MockTest::query()
                ->where('module', MockTest::MODULE_LISTENING)
                ->whereKey($value)
                ->firstOrFail();
        });

        RateLimiter::for('login', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('reset-password', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });
    }
}
