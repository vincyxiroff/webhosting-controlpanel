<?php

namespace App\Domain\StateMachine;

final class TransitionValidator
{
    private const EDGES = [
        'PENDING_PROVISION' => ['PROVISIONING', 'RECONCILING', 'DELETING', 'SUSPENDED_BILLING'],
        'PROVISIONING' => ['ACTIVE', 'RECONCILING', 'DEGRADED', 'DELETING', 'SUSPENDED_BILLING'],
        'ACTIVE' => ['UPDATING', 'RECONCILING', 'SUSPENDED_BILLING', 'SUSPENDED_MANUAL', 'DEGRADED', 'DELETING'],
        'UPDATING' => ['ACTIVE', 'RECONCILING', 'DEGRADED', 'SUSPENDED_BILLING', 'DELETING'],
        'SUSPENDED_BILLING' => ['ACTIVE', 'DELETING'],
        'SUSPENDED_MANUAL' => ['ACTIVE', 'DELETING', 'SUSPENDED_BILLING'],
        'DEGRADED' => ['RECONCILING', 'ACTIVE', 'SUSPENDED_BILLING', 'DELETING'],
        'RECONCILING' => ['ACTIVE', 'DEGRADED', 'SUSPENDED_BILLING', 'DELETING'],
        'DELETING' => ['DELETED'],
        'DELETED' => [],
    ];

    public function assertAllowed(?SiteState $from, SiteState $to): void
    {
        if ($from === null) {
            return;
        }
        if (! in_array($to->value, self::EDGES[$from->value] ?? [], true) && $from !== $to) {
            throw new \DomainException("Invalid site state transition {$from->value} -> {$to->value}");
        }
    }

    public function table(): array
    {
        return self::EDGES;
    }
}
