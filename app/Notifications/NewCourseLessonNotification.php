<?php

namespace App\Notifications;

use App\Models\Course;
use App\Models\Lesson;
use App\Notifications\Concerns\StoresDatabaseNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewCourseLessonNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use StoresDatabaseNotification;

    public function __construct(public readonly Course $course, public readonly Lesson $lesson) {}

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
            'new_course_lesson',
            'New course lesson',
            'A new lesson "'.$this->lesson->title.'" was added to '.$this->course->title.'.',
            [
                'course_id' => $this->course->id,
                'course_title' => $this->course->title,
                'course_slug' => $this->course->slug,
                'lesson_id' => $this->lesson->id,
                'lesson_title' => $this->lesson->title,
                'url' => '/learning/'.$this->course->slug,
            ],
        );
    }
}
