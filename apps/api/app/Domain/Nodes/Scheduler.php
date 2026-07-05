<?php

namespace App\Domain\Nodes;

use App\Domain\Scheduler\PlacementDecision;
use App\Domain\Scheduler\PlacementRequest;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Scheduler
{
    public function selectNodeForSite(array $plan, array $requirements): array
    {
        $request = PlacementRequest::fromPlan($plan, $requirements);
        $nodes = DB::table('nodes')
            ->where('status', 'online')
            ->whereJsonContains('roles', 'web')
            ->where('draining', false)
            ->get();

        $decisions = $nodes
            ->map(fn (object $node): PlacementDecision => $this->scoreNode($node, $request))
            ->filter(fn (PlacementDecision $decision): bool => $decision->eligible)
            ->sortByDesc(fn (PlacementDecision $decision): float => $decision->score)
            ->values();

        if ($decisions->isEmpty()) {
            DB::table('placement_decisions')->insert([
                'id' => (string) str()->uuid(),
                'tenant_id' => $request->tenantId,
                'site_id' => $request->siteId,
                'selected_node_id' => null,
                'status' => 'rejected',
                'score' => 0,
                'requirements' => json_encode($request->toArray(), JSON_THROW_ON_ERROR),
                'decision' => json_encode(['reason' => 'no_node_satisfied_hard_constraints'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            throw new RuntimeException('No eligible node satisfies placement constraints.');
        }

        $winner = $decisions->first();
        DB::table('placement_decisions')->insert([
            'id' => (string) str()->uuid(),
            'tenant_id' => $request->tenantId,
            'site_id' => $request->siteId,
            'selected_node_id' => $winner->nodeId,
            'status' => 'selected',
            'score' => $winner->score,
            'requirements' => json_encode($request->toArray(), JSON_THROW_ON_ERROR),
            'decision' => json_encode($winner->toArray(), JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $winner->toArray();
    }

    private function scoreNode(object $node, PlacementRequest $request): PlacementDecision
    {
        $metrics = json_decode($node->latest_metrics ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        $capabilities = json_decode($node->capabilities ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        $labels = json_decode($node->labels ?? '{}', true, 512, JSON_THROW_ON_ERROR);

        $allocatedCpu = (int) ($metrics['allocated_cpu_millicores'] ?? 0);
        $allocatedRam = (int) ($metrics['allocated_memory_mb'] ?? 0);
        $allocatedDisk = (int) ($metrics['allocated_disk_mb'] ?? 0);
        $reservedCpu = (int) ($capabilities['reserved_cpu_millicores'] ?? 0);
        $reservedRam = (int) ($capabilities['reserved_memory_mb'] ?? 0);
        $reservedDisk = (int) ($capabilities['reserved_disk_mb'] ?? 0);

        $cpuLimit = (int) (($capabilities['cpu_millicores'] ?? 0) * $request->overcommitPolicy['cpu']);
        $ramLimit = (int) (($capabilities['memory_mb'] ?? 0) * $request->overcommitPolicy['memory']);
        $diskLimit = (int) (($capabilities['disk_mb'] ?? 0) * $request->overcommitPolicy['disk']);

        $capacity = [
            'cpu_free' => $cpuLimit - $allocatedCpu - $reservedCpu,
            'memory_free' => $ramLimit - $allocatedRam - $reservedRam,
            'disk_free' => $diskLimit - $allocatedDisk - $reservedDisk,
        ];

        $hardFailures = [];
        if ($capacity['cpu_free'] < $request->cpuMillicores) {
            $hardFailures[] = 'cpu_capacity';
        }
        if ($capacity['memory_free'] < $request->memoryMb) {
            $hardFailures[] = 'memory_capacity';
        }
        if ($capacity['disk_free'] < $request->diskMb) {
            $hardFailures[] = 'disk_capacity';
        }
        if ($request->region !== null && ($node->region ?? null) !== $request->region) {
            $hardFailures[] = 'region_mismatch';
        }
        foreach ($request->requiredLabels as $key => $value) {
            if (($labels[$key] ?? null) !== $value) {
                $hardFailures[] = 'missing_label:' . $key;
            }
        }

        $eligible = $hardFailures === [];
        $pressure = (($metrics['cpu_percent'] ?? 100) * 0.42)
            + (($metrics['memory_percent'] ?? 100) * 0.34)
            + (($metrics['disk_percent'] ?? 100) * 0.14)
            + (($metrics['io_wait_percent'] ?? 0) * 0.10);

        $headroomScore = min(40, ($capacity['cpu_free'] / max(1, $request->cpuMillicores)) * 8)
            + min(30, ($capacity['memory_free'] / max(1, $request->memoryMb)) * 6)
            + min(15, ($capacity['disk_free'] / max(1, $request->diskMb)) * 3);
        $slaScore = $request->tierPriority * 5;
        $affinityScore = in_array($node->id, $request->preferredNodeIds, true) ? 15 : 0;
        $antiAffinityPenalty = in_array($node->id, $request->antiAffinityNodeIds, true) ? 100 : 0;
        $score = $eligible ? max(0, $headroomScore + $slaScore + $affinityScore + (100 - $pressure) - $antiAffinityPenalty) : 0;

        return new PlacementDecision(
            nodeId: $node->id,
            eligible: $eligible,
            score: round($score, 3),
            reasons: $eligible ? ['eligible_binpack_best_fit'] : $hardFailures,
            capacity: $capacity,
            pressure: round($pressure, 3),
        );
    }
}

