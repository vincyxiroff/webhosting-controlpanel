<?php

namespace App\Domain\StateMachine;

final class PriorityRules
{
    public function priority(StateSource $source): int
    {
        return match ($source) {
            StateSource::Billing => 500,
            StateSource::Security => 400,
            StateSource::Manual => 300,
            StateSource::Consistency => 200,
            StateSource::Scheduler => 100,
        };
    }

    public function canOverride(StateSource $incoming, int $currentPriority): bool
    {
        return $this->priority($incoming) >= $currentPriority;
    }
}

