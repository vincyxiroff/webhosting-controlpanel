<?php

namespace App\Domain\Scheduler;

final readonly class PlacementRequest
{
    public function __construct(
        public ?string $tenantId,
        public ?string $siteId,
        public int $cpuMillicores,
        public int $memoryMb,
        public int $diskMb,
        public ?string $region,
        public int $tierPriority,
        public array $requiredLabels,
        public array $preferredNodeIds,
        public array $antiAffinityNodeIds,
        public array $overcommitPolicy,
    ) {
    }

    public static function fromPlan(array $plan, array $requirements): self
    {
        $schedulerPolicy = self::decode($plan['scheduler_policy'] ?? []);
        $tierPriority = match ($plan['tier'] ?? 'standard') {
            'enterprise' => 5,
            'business' => 4,
            'reseller' => 3,
            'standard' => 2,
            default => 1,
        };

        return new self(
            tenantId: $requirements['tenant_id'] ?? null,
            siteId: $requirements['site_id'] ?? null,
            cpuMillicores: (int) ($requirements['cpu_millicores'] ?? $plan['cpu_millicores'] ?? 0),
            memoryMb: (int) ($requirements['memory_mb'] ?? $plan['memory_mb'] ?? 0),
            diskMb: (int) ($requirements['disk_mb'] ?? $plan['disk_mb'] ?? 0),
            region: $requirements['region'] ?? $schedulerPolicy['region'] ?? null,
            tierPriority: (int) ($schedulerPolicy['priority'] ?? $tierPriority),
            requiredLabels: $schedulerPolicy['required_labels'] ?? [],
            preferredNodeIds: $schedulerPolicy['preferred_node_ids'] ?? [],
            antiAffinityNodeIds: $schedulerPolicy['anti_affinity_node_ids'] ?? [],
            overcommitPolicy: $schedulerPolicy['overcommit'] ?? ['cpu' => 1.0, 'memory' => 1.0, 'disk' => 1.0],
        );
    }

    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'site_id' => $this->siteId,
            'cpu_millicores' => $this->cpuMillicores,
            'memory_mb' => $this->memoryMb,
            'disk_mb' => $this->diskMb,
            'region' => $this->region,
            'tier_priority' => $this->tierPriority,
            'required_labels' => $this->requiredLabels,
            'preferred_node_ids' => $this->preferredNodeIds,
            'anti_affinity_node_ids' => $this->antiAffinityNodeIds,
            'overcommit_policy' => $this->overcommitPolicy,
        ];
    }

    private static function decode(mixed $value): array
    {
        if (is_string($value)) {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        }

        return is_array($value) ? $value : [];
    }
}

