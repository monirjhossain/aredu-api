<?php

namespace App\Notifications;

use App\Models\MockTest;
use App\Models\TestAttempt;
use App\Notifications\Concerns\StoresDatabaseNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class MockTestResultAvailableNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use StoresDatabaseNotification;

    public function __construct(public readonly TestAttempt $attempt, public readonly MockTest $mockTest) {}

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
        $module = $this->mockTest->module === MockTest::MODULE_LISTENING ? 'listening' : 'reading';

        return $this->databasePayload(
            'mock_test_result_available',
            'Mock test result available',
            'Your results for "'.$this->mockTest->title.'" are ready.',
            [
                'attempt_id' => $this->attempt->id,
                'mock_test_id' => $this->mockTest->id,
                'mock_test_title' => $this->mockTest->title,
                'module' => $this->mockTest->module,
                'url' => '/'.$module.'-results',
            ],
        );
    }
}
