<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('payments.manage');
    }

    public function refund(User $user, Payment $payment): bool
    {
        return $user->hasPermission('payments.manage');
    }
}
