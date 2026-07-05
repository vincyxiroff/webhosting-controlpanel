<?php

namespace App\Support;

use DateTimeImmutable;

final readonly class DomainEvent
{
    public function __construct(
        public string $name,
        public string $aggregateType,
        public string $aggregateId,
        public array $payload,
        public ?string $tenantId,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {
    }
}

