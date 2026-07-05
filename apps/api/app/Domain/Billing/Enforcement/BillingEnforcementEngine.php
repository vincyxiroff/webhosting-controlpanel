<?php

namespace App\Domain\Billing\Enforcement;

use App\Domain\Commands\AgentCommandService;
use App\Domain\Events\OperationJournal;
use App\Domain\StateMachine\SiteState;
use App\Domain\StateMachine\StateMachineService;
use App\Domain\StateMachine\StateSource;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BillingEnforcementEngine
{
    public function __construct(
        private readonly PlanLimitResolver $limits,
        private readonly AntiAbuseDetector $abuse,
        private readonly AgentCommandService $commands,
        private readonly StateMachineService $states,
        private readonly OperationJournal $journal,
    ) {
    }

    public function run(string $window = '5m', int $limit = 500): array
    {
        $rollups = DB::table('tenant_usage_rollups')
            ->where('window', $window)
            ->orderByDesc('window_started_at')
            ->limit($limit)
            ->get()
            ->unique('tenant_id');

        $checked = 0;
        $decisions = 0;
        foreach ($rollups as $rollup) {
            $decision = $this->evaluateTenant($rollup);
            if ($decision['decision'] !== 'allow') {
                $this->persistAndDispatch($rollup, $decision);
                $decisions++;
            }
            $checked++;
        }

        return compact('checked', 'decisions');
    }

    public function evaluateTenant(object $rollup): array
    {
        $policy = $this->limits->limitsForTenant($rollup->tenant_id);
        $violations = [];
        foreach ($policy['limits'] as $metric => $limit) {
            $actual = (float) ($rollup->{$metric} ?? 0);
            $soft = (float) $limit * (float) ($policy['soft']['ratio'] ?? 0.8);
            $hard = (float) $limit * (float) ($policy['hard']['ratio'] ?? 1.0);
            if ($actual >= $hard) {
                $violations[] = ['metric' => $metric, 'level' => 'hard', 'actual' => $actual, 'limit' => $limit];
            } elseif ($actual >= $soft) {
                $violations[] = ['metric' => $metric, 'level' => 'soft', 'actual' => $actual, 'limit' => $limit];
            }
        }

        $abuseSignals = $this->abuse->detect($rollup);
        if ($policy['billing_status'] !== 'active') {
            return ['decision' => 'suspend', 'severity' => 'hard', 'violations' => [['metric' => 'billing_status', 'level' => 'hard', 'actual' => $policy['billing_status'], 'limit' => 'active']], 'abuse' => $abuseSignals];
        }
        if ($abuseSignals !== []) {
            return ['decision' => 'isolate', 'severity' => 'hard', 'violations' => $violations, 'abuse' => $abuseSignals];
        }
        if (collect($violations)->contains(fn (array $v): bool => $v['level'] === 'hard')) {
            return ['decision' => 'suspend', 'severity' => 'hard', 'violations' => $violations, 'abuse' => []];
        }
        if ($violations !== []) {
            return ['decision' => 'throttle', 'severity' => 'soft', 'violations' => $violations, 'abuse' => []];
        }

        return ['decision' => 'allow', 'severity' => 'none', 'violations' => [], 'abuse' => []];
    }

    private function persistAndDispatch(object $rollup, array $decision): void
    {
        $key = hash('sha256', json_encode([
            'tenant_id' => $rollup->tenant_id,
            'window' => $rollup->window,
            'window_started_at' => $rollup->window_started_at,
            'decision' => $decision['decision'],
        ], JSON_THROW_ON_ERROR));

        $existing = DB::table('billing_enforcement_decisions')->where('idempotency_key', $key)->first();
        DB::table('billing_enforcement_decisions')->updateOrInsert(
            ['idempotency_key' => $key],
            [
                'id' => $existing->id ?? (string) str()->uuid(),
                'tenant_id' => $rollup->tenant_id,
                'decision' => $decision['decision'],
                'severity' => $decision['severity'],
                'violations' => json_encode(['limits' => $decision['violations'], 'abuse' => $decision['abuse']], JSON_THROW_ON_ERROR),
                'usage_snapshot' => json_encode((array) $rollup, JSON_THROW_ON_ERROR),
                'limits_snapshot' => json_encode($this->limits->limitsForTenant($rollup->tenant_id), JSON_THROW_ON_ERROR),
                'status' => 'queued',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
        $this->journal->record($rollup->tenant_id, $this->operationForDecision($decision['decision']), 'billing', 'billing-enforcement', 'tenant', $rollup->tenant_id, [
            'tenant_id' => $rollup->tenant_id,
            'window' => $rollup->window,
            'window_started_at' => $rollup->window_started_at,
            'decision' => $decision,
        ], 'billing-decision:' . $key, ['severity' => $decision['severity']], 'billing:' . $key);

        $sites = DB::table('sites')->where('tenant_id', $rollup->tenant_id)->whereNotNull('node_id')->get();
        foreach ($sites as $site) {
            $command = match ($decision['decision']) {
                'throttle' => 'site.throttle',
                'isolate' => 'site.isolate',
                'suspend' => 'site.suspend',
                default => null,
            };
            if ($command === null) {
                continue;
            }

            $targetState = match ($decision['decision']) {
                'isolate' => SiteState::Degraded,
                'suspend' => SiteState::SuspendedBilling,
                default => null,
            };
            if ($targetState !== null) {
                $source = $decision['decision'] === 'isolate' ? StateSource::Security : StateSource::Billing;
                try {
                    $result = $this->states->transition($rollup->tenant_id, $site->id, $targetState, $source, 'billing-state:' . $key . ':' . $site->id . ':' . $targetState->value, [
                        'decision' => $decision['decision'],
                        'window' => $rollup->window,
                        'window_started_at' => $rollup->window_started_at,
                    ]);
                    if (($result['deferred'] ?? false) === true) {
                        continue;
                    }
                } catch (Throwable) {
                    continue;
                }
            }

            $this->commands->create(
                nodeId: $site->node_id,
                command: $command,
                payload: [
                    'site_id' => $site->id,
                    'tenant_id' => $rollup->tenant_id,
                    'domain' => $site->primary_domain,
                    'decision' => $decision,
                ],
                idempotencyKey: 'billing:' . $key . ':' . $site->id . ':' . $command,
                siteId: $site->id,
                type: 'billing-enforcement',
            );
        }
    }

    private function operationForDecision(string $decision): string
    {
        return match ($decision) {
            'throttle' => 'BillingSoftLimitExceeded',
            'isolate' => 'SecurityAbuseDetected',
            'suspend' => 'BillingLimitExceeded',
            default => 'BillingDecisionRecorded',
        };
    }
}
