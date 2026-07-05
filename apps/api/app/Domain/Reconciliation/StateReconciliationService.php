<?php

namespace App\Domain\Reconciliation;

use App\Domain\Commands\AgentCommandService;
use Illuminate\Support\Facades\DB;

final class StateReconciliationService
{
    public function __construct(private readonly AgentCommandService $commands)
    {
    }

    public function reconcileSite(string $siteId): array
    {
        $site = DB::table('sites')->where('id', $siteId)->firstOrFail();
        $actual = DB::table('site_actual_states')->where('site_id', $siteId)->first();

        if ($actual === null) {
            $this->commands->create($site->node_id, 'site.create', [
                'site_id' => $siteId,
                'primary_domain' => $site->primary_domain,
                'runtime' => $site->runtime,
                'runtime_version' => $site->runtime_version,
            ], 'reconcile:create:' . $siteId, $siteId, 'reconciliation');

            return ['drift' => 'missing_actual_state', 'action' => 'site.create'];
        }

        $drift = [];
        if ($site->status === 'active' && $actual->container_status !== 'running') {
            $drift[] = 'container_not_running';
            $this->commands->create($site->node_id, 'service.start', ['site_id' => $siteId], 'reconcile:start:' . $siteId, $siteId, 'reconciliation');
        }
        if ($actual->nginx_status !== 'synced') {
            $drift[] = 'nginx_not_synced';
            $this->commands->create($site->node_id, 'nginx.configure', [
                'site_id' => $siteId,
                'domain' => $site->primary_domain,
            ], 'reconcile:nginx:' . $siteId, $siteId, 'reconciliation');
        }

        DB::table('site_actual_states')->where('site_id', $siteId)->update([
            'drift' => json_encode($drift, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);

        return ['drift' => $drift, 'action' => $drift === [] ? 'none' : 'queued_repair'];
    }
}

