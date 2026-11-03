<?php

namespace App\Notifications;

use App\Models\WritingEvaluation;
use App\Models\WritingSubmission;
use App\Notifications\Concerns\StoresDatabaseNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class WritingEvaluationCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use StoresDatabaseNotification;

    public function __construct(
        public readonly WritingSubmission $submission,
        public readonly WritingEvaluation $evaluation,
    ) {}

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
        $title = $this->submission->relationLoaded('task')
            ? (string) $this->submission->task?->title
            : 'your writing';

        return $this->databasePayload(
            'writing_evaluation_completed',
            'Writing evaluation completed',
            'Your writing task "'.$title.'" has been evaluated.',
            [
                'submission_id' => $this->submission->id,
                'writing_task_id' => $this->submission->writing_task_id,
                'overall_band' => $this->evaluation->overall_band,
                'url' => '/writing-tasks/'.$this->submission->writing_task_id,
            ],
        );
    }
}
