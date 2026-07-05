<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class EventRecorder
{
    public function record(DomainEvent $event): void
    {
        DB::table('domain_events')->insert([
            'id' => (string) str()->uuid(),
            'tenant_id' => $event->tenantId,
            'name' => $event->name,
            'aggregate_type' => $event->aggregateType,
            'aggregate_id' => $event->aggregateId,
            'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR),
            'occurred_at' => $event->occurredAt->format('Y-m-d H:i:s.uP'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

