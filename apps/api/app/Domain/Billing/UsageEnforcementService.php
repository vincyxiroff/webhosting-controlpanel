<?php

namespace App\Domain\Billing;

use Illuminate\Support\Facades\DB;

final class UsageEnforcementService
{
    public function enforceSite(string $siteId): array
    {
        $site = DB::table('sites')->where('id', $siteId)->first();
        if ($site === null) {
            throw new \RuntimeException('Site not found.');
        }

        $quotas = json_decode($site->quotas, true, 512, JSON_THROW_ON_ERROR);
        $latest = DB::table('usage_aggregates')
            ->where('site_id', $siteId)
            ->where('bucket', '5m')
            ->orderByDesc('bucket_started_at')
            ->first();

        if ($latest === null) {
            return ['action' => 'none', 'reason' => 'no_usage'];
        }

        $violations = [];
        if ((float) $latest->cpu_millicores_avg > ($quotas['cpu_millicores'] ?? PHP_INT_MAX)) {
            $violations[] = 'cpu';
        }
        if ((int) $latest->memory_mb_max > ($quotas['memory_mb'] ?? PHP_INT_MAX)) {
            $violations[] = 'memory';
        }

        if ($violations === []) {
            return ['action' => 'none', 'reason' => 'within_quota'];
        }

        $action = count($violations) >= 2 ? 'suspend' : 'throttle';
        DB::table('enforcement_actions')->insert([
            'id' => (string) str()->uuid(),
            'tenant_id' => $site->tenant_id,
            'site_id' => $siteId,
            'action' => $action,
            'reason' => json_encode($violations, JSON_THROW_ON_ERROR),
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('node_commands')->insert([
            'id' => (string) str()->uuid(),
            'node_id' => $site->node_id,
            'command' => 'site.' . $action,
            'payload' => json_encode(['site_id' => $siteId, 'violations' => $violations], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'idempotency_key' => 'enforce:' . $siteId . ':' . $action . ':' . now()->format('YmdHi'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['action' => $action, 'violations' => $violations];
    }
}

