<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword
{
    protected function resetUrl($notifiable): string
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return $frontend.'/reset-password?token='.$this->token
            .'&email='.urlencode($notifiable->getEmailForPasswordReset());
    }

    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset your password')
            ->line('You are receiving this email because we received a password reset request.')
            ->action('Reset Password', $url)
            ->line('If you did not request a password reset, no further action is required.');
    }
}
