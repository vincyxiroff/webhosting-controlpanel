<?php

namespace App\Domain\Scheduler;

final readonly class PlacementDecision
{
    public function __construct(
        public string $nodeId,
        public bool $eligible,
        public float $score,
        public array $reasons,
        public array $capacity,
        public float $pressure,
    ) {
    }

    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'eligible' => $this->eligible,
            'score' => $this->score,
            'reasons' => $this->reasons,
            'capacity' => $this->capacity,
            'pressure' => $this->pressure,
        ];
    }
}

