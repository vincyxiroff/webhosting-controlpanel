<?php

namespace App\Domain\Events;

use Illuminate\Support\Facades\DB;

final class EventSequencer
{
    public function append(string $tenantId, string $eventType, string $source, array $payload, string $idempotencyKey): int
    {
        $existing = DB::table('ordered_events')->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return (int) $existing->sequence;
        }

        return DB::transaction(function () use ($tenantId, $eventType, $source, $payload, $idempotencyKey): int {
            $sequence = DB::table('tenant_event_sequences')->where('tenant_id', $tenantId)->lockForUpdate()->first();
            if ($sequence === null) {
                DB::table('tenant_event_sequences')->insert([
                    'tenant_id' => $tenantId,
                    'next_sequence' => 2,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $next = 1;
            } else {
                $next = (int) $sequence->next_sequence;
                DB::table('tenant_event_sequences')->where('tenant_id', $tenantId)->update([
                    'next_sequence' => $next + 1,
                    'updated_at' => now(),
                ]);
            }

            DB::table('ordered_events')->insert([
                'id' => (string) str()->uuid(),
                'tenant_id' => $tenantId,
                'sequence' => $next,
                'event_type' => $eventType,
                'source' => $source,
                'idempotency_key' => $idempotencyKey,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $next;
        });
    }
}

