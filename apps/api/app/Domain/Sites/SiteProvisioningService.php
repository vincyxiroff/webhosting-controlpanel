<?php

namespace App\Domain\Sites;

use App\Domain\Nodes\Scheduler;
use App\Domain\Commands\SiteLifecycleCommandBuilder;
use App\Domain\Consistency\DesiredStateProjector;
use App\Domain\Events\OperationJournal;
use App\Domain\StateMachine\SiteState;
use App\Domain\StateMachine\StateMachineService;
use App\Domain\StateMachine\StateSource;
use App\Support\DomainEvent;
use App\Support\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SiteProvisioningService
{
    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly EventRecorder $events,
        private readonly SiteLifecycleCommandBuilder $lifecycle,
        private readonly DesiredStateProjector $desiredStates,
        private readonly StateMachineService $states,
        private readonly OperationJournal $journal,
    ) {
    }

    public function create(array $input, string $tenantId, string $actorId): array
    {
        return DB::transaction(function () use ($input, $tenantId, $actorId): array {
            $plan = (array) DB::table('hosting_plans')->where('id', $input['plan_id'])->first();
            $requirements = [
                'cpu_millicores' => $input['cpu_millicores'] ?? $plan['cpu_millicores'],
                'memory_mb' => $input['memory_mb'] ?? $plan['memory_mb'],
                'disk_mb' => $input['disk_mb'] ?? $plan['disk_mb'],
            ];
            $placement = $this->scheduler->selectNodeForSite($plan, $requirements);
            $siteId = (string) Str::uuid();

            DB::table('sites')->insert([
                'id' => $siteId,
                'tenant_id' => $tenantId,
                'plan_id' => $input['plan_id'],
                'node_id' => $placement['node_id'],
                'name' => $input['name'],
                'primary_domain' => $input['primary_domain'],
                'runtime' => $input['runtime'],
                'runtime_version' => $input['runtime_version'],
                'status' => 'pending_provision',
                'repository' => json_encode($input['repository'] ?? null, JSON_THROW_ON_ERROR),
                'environment' => json_encode($input['environment'] ?? [], JSON_THROW_ON_ERROR),
                'quotas' => json_encode($requirements, JSON_THROW_ON_ERROR),
                'created_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->events->record(new DomainEvent('site.created', 'site', $siteId, $input, $tenantId));
            $this->events->record(new DomainEvent('site.scheduled', 'site', $siteId, $placement, $tenantId));
            $this->journal->record($tenantId, 'ProvisionRequested', 'lifecycle', 'manual', 'site', $siteId, [
                'site_id' => $siteId,
                'name' => $input['name'],
                'domain' => $input['primary_domain'],
                'runtime' => $input['runtime'],
                'plan_id' => $input['plan_id'],
            ], 'provision-requested:' . $siteId, ['actor_id' => $actorId], 'site:' . $siteId, null, $actorId, $siteId);
            $this->journal->record($tenantId, 'NodeAllocated', 'scheduler', 'scheduler', 'site', $siteId, [
                'site_id' => $siteId,
                'node_id' => $placement['node_id'],
                'placement' => $placement,
                'requirements' => $requirements,
            ], 'node-allocated:' . $siteId . ':' . $placement['node_id'], [], 'site:' . $siteId, 'provision-requested:' . $siteId, $actorId, $siteId, $placement['node_id']);
            $this->states->transition($tenantId, $siteId, SiteState::PendingProvision, StateSource::Scheduler, 'site:' . $siteId . ':pending-provision', [
                'actor_id' => $actorId,
                'node_id' => $placement['node_id'],
            ]);
            $this->states->transition($tenantId, $siteId, SiteState::Provisioning, StateSource::Scheduler, 'site:' . $siteId . ':provisioning', [
                'actor_id' => $actorId,
                'node_id' => $placement['node_id'],
            ]);
            $this->desiredStates->projectSite($siteId);

            $this->lifecycle->create($placement['node_id'], $siteId, $input + ['quotas' => $requirements]);

            return ['id' => $siteId, 'status' => 'provisioning', 'placement' => $placement];
        });
    }
}
