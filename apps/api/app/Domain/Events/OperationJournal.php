<?php

namespace App\Domain\Events;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OperationJournal
{
    public function __construct(private readonly EventSequencer $events)
    {
    }

    public function record(
        ?string $tenantId,
        string $operationName,
        string $category,
        string $source,
        string $entityType,
        ?string $entityId,
        array $payload,
        string $idempotencyKey,
        array $metadata = [],
        ?string $correlationId = null,
        ?string $causationId = null,
        ?string $actorId = null,
        ?string $siteId = null,
        ?string $nodeId = null,
        ?string $commandId = null,
        ?int $sequence = null,
    ): string {
        $existing = DB::table('operation_journal')->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing->id;
        }

        if ($tenantId !== null && $sequence === null) {
            $sequence = $this->events->append($tenantId, 'operation.' . $operationName, $source, [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'site_id' => $siteId,
                'node_id' => $nodeId,
                'command_id' => $commandId,
                'payload' => $payload,
            ], $idempotencyKey);
        }

        $id = (string) Str::uuid();
        DB::table('operation_journal')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'sequence' => $sequence,
            'operation_name' => $operationName,
            'category' => $category,
            'source' => $source,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'site_id' => $siteId,
            'node_id' => $nodeId,
            'command_id' => $commandId,
            'correlation_id' => $correlationId ?? $idempotencyKey,
            'causation_id' => $causationId,
            'idempotency_key' => $idempotencyKey,
            'actor_id' => $actorId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function recordWithSequence(
        string $tenantId,
        int $sequence,
        string $operationName,
        string $category,
        string $source,
        string $entityType,
        ?string $entityId,
        array $payload,
        string $idempotencyKey,
        array $metadata = [],
        ?string $correlationId = null,
        ?string $causationId = null,
        ?string $actorId = null,
        ?string $siteId = null,
        ?string $nodeId = null,
        ?string $commandId = null,
    ): string {
        return $this->record($tenantId, $operationName, $category, $source, $entityType, $entityId, $payload, $idempotencyKey, $metadata, $correlationId, $causationId, $actorId, $siteId, $nodeId, $commandId, $sequence);
    }

    public function timeline(?string $tenantId, string $entityType, string $entityId, int $limit = 200): array
    {
        $query = DB::table('operation_journal')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId);
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->orderBy('sequence')->orderBy('occurred_at')->limit($limit)->get()->all();
    }

    public function rebuildSite(string $siteId, ?string $tenantId = null): array
    {
        $state = [
            'site_id' => $siteId,
            'status' => null,
            'node_id' => null,
            'commands' => [],
            'last_operation' => null,
        ];

        foreach ($this->timeline($tenantId, 'site', $siteId, 1000) as $event) {
            $payload = json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR);
            $state['last_operation'] = $event->operation_name;
            if (isset($payload['to'])) {
                $state['status'] = strtolower((string) $payload['to']);
            }
            if (isset($payload['node_id'])) {
                $state['node_id'] = $payload['node_id'];
            }
            if ($event->command_id !== null) {
                $state['commands'][$event->command_id] = [
                    'operation' => $event->operation_name,
                    'sequence' => $event->sequence,
                    'payload' => $payload,
                ];
            }
        }

        return $state;
    }

    public function snapshotSite(string $siteId, ?string $tenantId = null): array
    {
        $state = $this->rebuildSite($siteId, $tenantId);
        $lastSequence = (int) (DB::table('operation_journal')
            ->where('entity_type', 'site')
            ->where('entity_id', $siteId)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->max('sequence') ?? 0);
        $checksum = hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
        $existing = DB::table('operation_snapshots')->where('entity_type', 'site')->where('entity_id', $siteId)->first();
        DB::table('operation_snapshots')->updateOrInsert(
            ['entity_type' => 'site', 'entity_id' => $siteId],
            [
                'id' => $existing->id ?? (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'last_sequence' => $lastSequence,
                'version' => ($existing->version ?? 0) + 1,
                'state' => json_encode($state, JSON_THROW_ON_ERROR),
                'checksum' => $checksum,
                'updated_at' => now(),
                'created_at' => $existing->created_at ?? now(),
            ],
        );

        return ['state' => $state, 'last_sequence' => $lastSequence, 'checksum' => $checksum];
    }
}
