<?php

namespace App\Notifications;

use App\Models\SpeakingEvaluation;
use App\Models\SpeakingRecording;
use App\Notifications\Concerns\StoresDatabaseNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SpeakingEvaluationCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use StoresDatabaseNotification;

    public function __construct(
        public readonly SpeakingRecording $recording,
        public readonly SpeakingEvaluation $evaluation,
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
        $title = $this->recording->relationLoaded('task')
            ? (string) $this->recording->task?->title
            : 'your speaking';

        return $this->databasePayload(
            'speaking_evaluation_completed',
            'Speaking evaluation completed',
            'Your speaking task "'.$title.'" has been evaluated.',
            [
                'recording_id' => $this->recording->id,
                'speaking_task_id' => $this->recording->speaking_task_id,
                'overall_band' => $this->evaluation->overall_band,
                'url' => '/speaking-tasks/'.$this->recording->speaking_task_id,
            ],
        );
    }
}
