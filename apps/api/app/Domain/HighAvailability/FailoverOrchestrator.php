<?php

namespace App\Domain\HighAvailability;

use App\Domain\Nodes\Scheduler;
use Illuminate\Support\Facades\DB;

final class FailoverOrchestrator
{
    public function detectAndQueue(int $heartbeatGraceSeconds = 60): int
    {
        $offlineNodes = DB::table('nodes')
            ->where('status', 'online')
            ->where('last_heartbeat_at', '<', now()->subSeconds($heartbeatGraceSeconds))
            ->get();

        foreach ($offlineNodes as $node) {
            DB::table('nodes')->where('id', $node->id)->update([
                'status' => 'offline',
                'updated_at' => now(),
            ]);

            DB::table('failover_incidents')->insert([
                'id' => (string) str()->uuid(),
                'node_id' => $node->id,
                'status' => 'detected',
                'severity' => 'critical',
                'detected_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $offlineNodes->count();
    }

    public function planSiteRecovery(string $siteId, Scheduler $scheduler): array
    {
        $site = DB::table('sites')->where('id', $siteId)->firstOrFail();
        $plan = (array) DB::table('hosting_plans')->where('id', $site->plan_id)->firstOrFail();
        $requirements = json_decode($site->quotas, true, 512, JSON_THROW_ON_ERROR) + [
            'tenant_id' => $site->tenant_id,
            'site_id' => $site->id,
        ];
        $placement = $scheduler->selectNodeForSite($plan, $requirements);

        DB::table('migration_jobs')->insert([
            'id' => (string) str()->uuid(),
            'source_node_id' => $site->node_id,
            'target_node_id' => $placement['node_id'],
            'site_id' => $siteId,
            'status' => 'queued',
            'plan' => json_encode([
                'mode' => 'failover_restore',
                'restore_latest_backup' => true,
                'reroute_edge' => true,
                'sync_dns' => true,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $placement;
    }
}

