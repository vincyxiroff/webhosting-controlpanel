<?php

namespace App\Domain\StateMachine;

enum SiteState: string
{
    case PendingProvision = 'PENDING_PROVISION';
    case Provisioning = 'PROVISIONING';
    case Active = 'ACTIVE';
    case Updating = 'UPDATING';
    case SuspendedBilling = 'SUSPENDED_BILLING';
    case SuspendedManual = 'SUSPENDED_MANUAL';
    case Degraded = 'DEGRADED';
    case Reconciling = 'RECONCILING';
    case Deleting = 'DELETING';
    case Deleted = 'DELETED';
}

