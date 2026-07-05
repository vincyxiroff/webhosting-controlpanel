<?php

namespace App\Domain\StateMachine;

use App\Domain\Conflicts\ConflictResolver;
use App\Domain\Events\EventSequencer;
use App\Domain\Events\OperationJournal;
use App\Domain\Locking\LockManager;
use Illuminate\Support\Facades\DB;

final class StateMachineService
{
    public function __construct(
        private readonly LockManager $locks,
        private readonly TransitionValidator $validator,
        private readonly PriorityRules $priorities,
        private readonly EventSequencer $events,
        private readonly ConflictResolver $conflicts,
        private readonly OperationJournal $journal,
    ) {
    }

    public function transition(string $tenantId, string $siteId, SiteState $to, StateSource $source, string $idempotencyKey, array $context = []): array
    {
        $owner = $this->locks->owner($source->value);

        return $this->locks->withLock(LockManager::site($siteId), $owner, function () use ($tenantId, $siteId, $to, $source, $idempotencyKey, $context): array {
            return DB::transaction(function () use ($tenantId, $siteId, $to, $source, $idempotencyKey, $context): array {
                $current = DB::table('site_state_machines')->where('site_id', $siteId)->lockForUpdate()->first();
                if ($current !== null && $current->last_idempotency_key === $idempotencyKey) {
                    return ['state' => $current->state, 'version' => $current->version, 'idempotent' => true];
                }

                $from = $current ? SiteState::from($current->state) : null;
                $incomingPriority = $this->priorities->priority($source);
                if ($current !== null && ! $this->priorities->canOverride($source, (int) $current->priority)) {
                    $this->conflicts->decide($tenantId, $siteId, $source, $to->value, StateSource::from($current->source), $current->state);
                    $this->log($tenantId, $siteId, $from?->value, $to->value, $source, $incomingPriority, $idempotencyKey, $context, 'deferred', 'Lower priority transition deferred.');

                    return ['state' => $current->state, 'version' => $current->version, 'deferred' => true];
                }

                $this->validator->assertAllowed($from, $to);
                $version = ($current->version ?? 0) + 1;
                DB::table('site_state_machines')->updateOrInsert(
                    ['site_id' => $siteId],
                    [
                        'id' => $current->id ?? (string) str()->uuid(),
                        'tenant_id' => $tenantId,
                        'state' => $to->value,
                        'source' => $source->value,
                        'priority' => $incomingPriority,
                        'version' => $version,
                        'last_idempotency_key' => $idempotencyKey,
                        'context' => json_encode($context, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                        'created_at' => $current->created_at ?? now(),
                    ],
                );
                DB::table('sites')->where('id', $siteId)->update(['status' => strtolower($to->value), 'updated_at' => now()]);
                $sequence = $this->events->append($tenantId, 'site.state.transitioned', $source->value, [
                    'site_id' => $siteId,
                    'from' => $from?->value,
                    'to' => $to->value,
                    'version' => $version,
                    'context' => $context,
                ], $idempotencyKey);
                $this->journal->recordWithSequence($tenantId, $sequence, $this->operationForState($to), 'state', $source->value, 'site', $siteId, [
                    'site_id' => $siteId,
                    'from' => $from?->value,
                    'to' => $to->value,
                    'version' => $version,
                    'context' => $context,
                ], 'journal:' . $idempotencyKey, ['state_machine_version' => $version], $idempotencyKey, null, $context['actor_id'] ?? null, $siteId, $context['node_id'] ?? null);
                $this->log($tenantId, $siteId, $from?->value, $to->value, $source, $incomingPriority, $idempotencyKey, $context + ['sequence' => $sequence], 'applied');

                return ['state' => $to->value, 'version' => $version, 'sequence' => $sequence];
            });
        });
    }

    private function operationForState(SiteState $state): string
    {
        return match ($state) {
            SiteState::PendingProvision => 'ProvisionRequested',
            SiteState::Provisioning => 'ProvisionStarted',
            SiteState::Active => 'Activated',
            SiteState::Updating => 'UpdateRequested',
            SiteState::SuspendedBilling => 'BillingSuspended',
            SiteState::SuspendedManual => 'ManualSuspended',
            SiteState::Degraded => 'SecurityDegraded',
            SiteState::Reconciling => 'ReconciliationStarted',
            SiteState::Deleting => 'DeleteRequested',
            SiteState::Deleted => 'Deleted',
        };
    }

    public function current(string $siteId): ?object
    {
        return DB::table('site_state_machines')->where('site_id', $siteId)->first();
    }

    private function log(string $tenantId, string $siteId, ?string $from, string $to, StateSource $source, int $priority, string $idempotencyKey, array $context, string $result, ?string $message = null): void
    {
        DB::table('state_transition_logs')->insert([
            'id' => (string) str()->uuid(),
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'from_state' => $from,
            'to_state' => $to,
            'source' => $source->value,
            'priority' => $priority,
            'idempotency_key' => $idempotencyKey,
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
            'result' => $result,
            'message' => $message,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
