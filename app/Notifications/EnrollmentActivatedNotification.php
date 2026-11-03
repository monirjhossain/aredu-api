<?php

namespace App\Notifications;

use App\Models\Course;
use App\Models\Enrollment;
use App\Notifications\Concerns\StoresDatabaseNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class EnrollmentActivatedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use StoresDatabaseNotification;

    public function __construct(public readonly Enrollment $enrollment, public readonly Course $course) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->databasePayload(
            'enrollment_activated',
            'New enrollment',
            'You are enrolled in '.$this->course->title.'.',
            [
                'enrollment_id' => $this->enrollment->id,
                'course_id' => $this->course->id,
                'course_title' => $this->course->title,
                'course_slug' => $this->course->slug,
                'url' => '/learning/'.$this->course->slug,
            ],
        );
    }
}
