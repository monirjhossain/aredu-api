<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Payment;
use App\Notifications\Concerns\StoresDatabaseNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PaymentFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use StoresDatabaseNotification;

    public function __construct(public readonly Payment $payment, public readonly Order $order) {}

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
            'payment_failed',
            'Payment failed',
            'Your payment for order #'.$this->order->id.' failed.',
            [
                'payment_id' => $this->payment->id,
                'order_id' => $this->order->id,
                'url' => '/orders/'.$this->order->id,
            ],
        );
    }
}
