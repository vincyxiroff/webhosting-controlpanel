<?php

namespace App\Domain\Consistency;

use App\Domain\Commands\AgentCommandService;
use App\Domain\StateMachine\SiteState;
use App\Domain\StateMachine\StateMachineService;
use App\Domain\StateMachine\StateSource;
use Illuminate\Support\Facades\DB;
use Throwable;

final class GlobalConsistencyEngine
{
    public function __construct(
        private readonly DesiredStateProjector $desiredStates,
        private readonly StateDiffEngine $diffEngine,
        private readonly DriftResolver $resolver,
        private readonly AgentCommandService $commands,
        private readonly StateMachineService $states,
    ) {
    }

    public function run(int $limit = 500): array
    {
        $sites = DB::table('sites')
            ->whereIn('status', ['active', 'pending_provision', 'provisioning', 'updating', 'reconciling', 'degraded'])
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $checked = 0;
        $drifts = 0;
        foreach ($sites as $site) {
            if (! $this->canReconcile((string) $site->id)) {
                continue;
            }
            try {
                $transition = $this->states->transition($site->tenant_id, $site->id, SiteState::Reconciling, StateSource::Consistency, 'consistency:' . $site->id . ':run:' . now()->format('YmdHi'), [
                    'node_id' => $site->node_id,
                ]);
                if (($transition['deferred'] ?? false) === true) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }
            $desired = $this->desiredStates->projectSite($site->id);
            $actualRow = DB::table('actual_state_snapshots')
                ->where('site_id', $site->id)
                ->orderByDesc('reported_at')
                ->first();
            $actual = $actualRow ? json_decode($actualRow->snapshot, true, 512, JSON_THROW_ON_ERROR) : null;

            foreach ($this->diffEngine->diff($desired, $actual) as $drift) {
                $this->resolver->recordAndQueue($desired, $drift);
                $drifts++;
            }
            DB::table('desired_states')->where('site_id', $site->id)->update([
                'observed_at' => now(),
                'updated_at' => now(),
            ]);
            if ($this->diffEngine->diff($desired, $actual) === []) {
                $this->states->transition($site->tenant_id, $site->id, SiteState::Active, StateSource::Consistency, 'consistency:' . $site->id . ':clean:' . now()->format('YmdHi'), [
                    'node_id' => $site->node_id,
                ]);
            }
            $checked++;
        }

        return [
            'checked_sites' => $checked,
            'drifts_detected' => $drifts,
            'jobs_dispatched' => $this->resolver->dispatchDueJobs(),
            'timed_out_commands' => $this->commands->markTimeouts(),
        ];
    }

    private function canReconcile(string $siteId): bool
    {
        $current = $this->states->current($siteId);
        if ($current === null) {
            return true;
        }

        return in_array($current->state, [
            SiteState::PendingProvision->value,
            SiteState::Provisioning->value,
            SiteState::Active->value,
            SiteState::Updating->value,
            SiteState::Degraded->value,
            SiteState::Reconciling->value,
        ], true);
    }
}
