<?php

namespace App\Domain\StateMachine;

enum StateSource: string
{
    case Billing = 'billing';
    case Security = 'security';
    case Manual = 'manual';
    case Consistency = 'consistency';
    case Scheduler = 'scheduler';
}

